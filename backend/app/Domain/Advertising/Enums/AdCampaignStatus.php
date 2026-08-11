<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Enums;

/**
 * The lifecycle of a campaign, as an ADMINISTRATOR sees it.
 *
 * IMPORTANT: this column is not what decides whether an ad is served.
 *
 * Serving asks the dates directly — `status = active AND starts_at <= now AND
 * (ends_at IS NULL OR ends_at >= now)`. If serving trusted this column alone,
 * then the day the scheduled command failed to run, every expired campaign
 * would keep being served and every advertiser whose window had opened would
 * show nothing. A cron outage would become a billing dispute.
 *
 * So the column is a CACHE of the schedule for the admin list — it is what
 * makes "show me everything that is live" an indexed query instead of a scan
 * with date arithmetic — and `ads:refresh-statuses` keeps it in step. Paused is
 * the one status that is genuinely state rather than a function of time, which
 * is why it is the only one a human sets directly.
 */
enum AdCampaignStatus: string
{
    /** Being written. Never served, whatever its dates say. */
    case Draft = 'draft';

    /** Approved and dated, but its window has not opened yet. */
    case Scheduled = 'scheduled';

    /** In its window and serving. */
    case Active = 'active';

    /** Stopped by a human. Survives its window — resuming does not need re-dating. */
    case Paused = 'paused';

    /** Its window closed. */
    case Expired = 'expired';

    /** Retired from the list without destroying its reporting history. */
    case Archived = 'archived';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Expired => 'Expired',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether a campaign in this state may be served AT ALL.
     *
     * Note this is necessary, not sufficient — the dates are still checked. A
     * campaign is only eligible when this is true AND its window is open.
     */
    public function isServable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the scheduler may move a campaign out of this status.
     *
     * Draft, paused and archived are human decisions and the clock must not
     * override them: a paused campaign whose end date passes should stay
     * paused, not silently become expired, or resuming it would need the
     * advertiser to be re-dated by hand.
     */
    public function followsSchedule(): bool
    {
        return match ($this) {
            self::Scheduled, self::Active, self::Expired => true,
            self::Draft, self::Paused, self::Archived => false,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'is_servable' => $this->isServable(),
            'follows_schedule' => $this->followsSchedule(),
        ];
    }
}
