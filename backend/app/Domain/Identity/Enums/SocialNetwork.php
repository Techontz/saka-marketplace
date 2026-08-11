<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * The social networks a vendor may publish on their profile.
 *
 * A FIXED SET, because each one is a rendered icon. An open-ended list would
 * mean either an icon nobody drew or a link with no affordance at all, and the
 * public profile would grow blank squares as vendors invented networks.
 *
 * The real work here is `normalise()`. `seller_profiles.social_links` is a JSON
 * blob that has only ever been validated as "some http(s) URL", which lets four
 * different things through for one network:
 *
 *   instagram.com/kilimani          — no scheme, so it renders as a RELATIVE
 *                                     link and navigates to saka.africa/instagram.com/…
 *
 *   @kilimani                       — a handle, not a URL
 *   https://instagram.com/kilimani/ — fine
 *   https://evil.example/phish      — stored under the "instagram" key, so the
 *                                     profile shows an Instagram icon pointing
 *                                     somewhere else entirely
 *
 * The last one is the reason host checking is not optional. An icon is a claim
 * about where a link goes, and a marketplace that renders the Instagram glyph
 * over an arbitrary destination is lending its own credibility to it.
 */
enum SocialNetwork: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case X = 'x';
    case LinkedIn = 'linkedin';
    case TikTok = 'tiktok';
    case YouTube = 'youtube';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::X => 'X',
            self::LinkedIn => 'LinkedIn',
            self::TikTok => 'TikTok',
            self::YouTube => 'YouTube',
        };
    }

    /**
     * Hosts this network is allowed to point at.
     *
     * `www.` is stripped before comparison, so it is not listed. Regional and
     * legacy domains are included because vendors genuinely paste them —
     * refusing `twitter.com` for the X entry would reject a link that works.
     *
     * @return array<int, string>
     */
    public function hosts(): array
    {
        return match ($this) {
            self::Facebook => ['facebook.com', 'fb.com', 'm.facebook.com', 'web.facebook.com'],
            self::Instagram => ['instagram.com', 'instagr.am'],
            self::X => ['x.com', 'twitter.com', 'mobile.twitter.com'],
            self::LinkedIn => ['linkedin.com', 'tz.linkedin.com'],
            self::TikTok => ['tiktok.com', 'vm.tiktok.com'],
            self::YouTube => ['youtube.com', 'youtu.be', 'm.youtube.com'],
        };
    }

    /** The canonical base a bare handle is expanded against. */
    public function handleBase(): string
    {
        return match ($this) {
            self::Facebook => 'https://facebook.com/',
            self::Instagram => 'https://instagram.com/',
            self::X => 'https://x.com/',
            self::LinkedIn => 'https://linkedin.com/in/',
            self::TikTok => 'https://tiktok.com/@',
            self::YouTube => 'https://youtube.com/@',
        };
    }

    /**
     * Turn whatever the vendor typed into a canonical URL, or null.
     *
     * Null means "do not store this" — and a null is deleted rather than kept
     * as an empty string, because `{"instagram": ""}` renders as an Instagram
     * icon linking to nowhere, which is exactly the empty-icon problem the
     * public profile must not have.
     *
     * Accepted, in order:
     *   - a full http(s) URL on one of this network's hosts;
     *   - a scheme-less URL on one of those hosts ("instagram.com/kilimani");
     *   - a bare handle, with or without a leading @.
     *
     * Anything else — including a well-formed URL on the WRONG host — is
     * rejected. Silently rewriting it would be worse: the vendor would believe
     * their link was saved.
     */
    public function normalise(?string $input): ?string
    {
        $value = trim((string) $input);

        if ($value === '') {
            return null;
        }

        /*
         * A bare handle, checked before URL parsing.
         *
         * TWO ways to be one, and the first is not optional: a LEADING @ is an
         * explicit marker, and it has to win regardless of what follows.
         * Instagram and TikTok both allow dots in handles, so "@kilimani.properties"
         * would otherwise be read as a hostname, fail the host check, and be
         * silently discarded — a vendor's real handle rejected for looking like
         * a domain.
         *
         * Without the @, only something with no dot and no slash is treated as
         * a handle; "kilimani.properties" on its own is genuinely ambiguous and
         * is read as a host, which the host check then rejects.
         */
        $isHandle = str_starts_with($value, '@')
            || (! str_contains($value, '.') && ! str_contains($value, '/'));

        if ($isHandle) {
            $handle = ltrim($value, '@');

            return $this->isPlausibleHandle($handle) ? $this->handleBase().$handle : null;
        }

        // Scheme-less input. Assumed https rather than rejected: "instagram.com/x"
        // is what people copy out of a browser's address bar, and storing it
        // unqualified produces a relative link that navigates within SAKA.
        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://'.ltrim($value, '/');
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if (! in_array($host, $this->hosts(), true)) {
            return null;
        }

        // Rebuilt from the parsed parts rather than returned as typed, so
        // credentials (`https://user:pass@instagram.com/x`) and fragments are
        // dropped instead of being stored and rendered.
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        // Always https on the canonical host: these all serve TLS, and storing
        // http would ship a mixed-content link from an https page.
        return 'https://'.$host.$path.$query;
    }

    /**
     * Whether a bare handle is worth expanding.
     *
     * Deliberately loose — every network has its own rules and they change —
     * but tight enough to reject the obvious mistakes (an email address, a
     * phone number, a sentence).
     */
    private function isPlausibleHandle(string $handle): bool
    {
        return $handle !== ''
            && mb_strlen($handle) <= 64
            && preg_match('/^[A-Za-z0-9._-]+$/', $handle) === 1;
    }

    /**
     * Normalise a whole `social_links` map.
     *
     * Unknown keys are dropped and unusable values are removed entirely, so
     * what reaches the database is only ever a map of known networks to
     * canonical URLs. The public resource can then render it without
     * re-checking anything.
     *
     * @param  array<string, mixed>  $links
     * @return array<string, string>
     */
    public static function normaliseAll(array $links): array
    {
        /*
         * Iterated over the ENUM's order, not the input's.
         *
         * Two reasons. The public profile renders these as a row of icons, and
         * a row whose order depends on the sequence a vendor happened to fill
         * the form in looks arbitrary — every business should show them the
         * same way round.
         *
         * And the input order is not stable anyway: this is stored in a MySQL
         * JSON column, which normalises objects by reordering their keys, so a
         * map read back does not match the map written. Anything comparing the
         * two — a test, a diff in the audit log — would see spurious changes.
         */
        $normalised = [];

        // Case-insensitive lookup without assuming the caller lower-cased keys.
        $byKey = [];

        foreach ($links as $key => $value) {
            $byKey[strtolower(trim((string) $key))] = $value;
        }

        foreach (self::cases() as $network) {
            if (! array_key_exists($network->value, $byKey)) {
                continue;
            }

            $value = $byKey[$network->value];
            $url = $network->normalise(is_string($value) ? $value : null);

            if ($url !== null) {
                $normalised[$network->value] = $url;
            }
        }

        return $normalised;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'hosts' => $this->hosts(),
            'handle_base' => $this->handleBase(),
        ];
    }
}
