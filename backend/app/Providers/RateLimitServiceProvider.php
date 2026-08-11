<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters, Redis-backed so a limit holds across every app server.
 *
 * These sit BEHIND edge protection (Cloudflare/WAF), not instead of it — the
 * application limiter is the backstop that survives a direct-to-origin request.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Login: keyed on email AND ip so one attacker cannot lock out a victim
        // by hammering their address from elsewhere.
        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour(3)->by($request->ip()));

        RateLimiter::for('auth-oauth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // OTP send is the expensive one — every request costs money.
        RateLimiter::for('otp-request', fn (Request $request) => [
            Limit::perMinute(1)->by('otp:'.$request->input('phone', $request->ip())),
            Limit::perHour(config('saka.otp.max_per_hour'))->by('otp-hr:'.$request->input('phone', $request->ip())),
            Limit::perHour(20)->by('otp-ip:'.$request->ip()),
        ]);

        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perHour(3)->by('pwd:'.strtolower((string) $request->input('email'))),
            Limit::perHour(10)->by($request->ip()),
        ]);

        // Anonymous read surface.
        RateLimiter::for('api-public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Authenticated surface: keyed on the user so shared NAT egress does not
        // punish everyone behind one address.
        RateLimiter::for('api-authenticated', fn (Request $request) => $request->user()
            ? Limit::perMinute(120)->by('user:'.$request->user()->id)
            : Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ? 'user:'.$request->user()->id : $request->ip()));

        // Public unauthenticated write — the obvious spam target.
        RateLimiter::for('inquiry-create', fn (Request $request) => [
            Limit::perHour(5)->by('inq:'.strtolower((string) $request->input('email'))),
            Limit::perHour(15)->by($request->ip()),
        ]);

        // Reviews shape a seller's public reputation, so they get a dedicated
        // limit rather than sharing the generic authenticated budget.
        RateLimiter::for('review-create', fn (Request $request) => [
            Limit::perHour(10)->by('review:'.($request->user()?->id ?? $request->ip())),
            Limit::perDay(30)->by('review-day:'.($request->user()?->id ?? $request->ip())),
        ]);

        /*
         * Abuse reports are open to guests, which makes them a vector for
         * mass-flagging a competitor's listings. The per-hour cap is generous
         * enough that someone reporting a genuine scam ring is not stopped, and
         * tight enough that a script cannot bury a seller.
         */
        RateLimiter::for('listing-report', fn (Request $request) => [
            Limit::perHour(10)->by('report:'.($request->user()?->id ?? $request->ip())),
            Limit::perDay(30)->by('report-day:'.($request->user()?->id ?? $request->ip())),
        ]);

        RateLimiter::for('media-upload', fn (Request $request) => Limit::perHour(30)
            ->by('media:'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(300)
            ->by('admin:'.($request->user()?->id ?? $request->ip())));

        /*
         * Booking creation.
         *
         * Open to guests, so keyed on IP for anonymous callers. Generous enough
         * that a family booking three tutors in one sitting is not stopped,
         * tight enough that the endpoint cannot be used to fill a specialist's
         * diary with junk — which is the real abuse here, since every pending
         * booking holds a slot somebody else wanted.
         */
        RateLimiter::for('booking-create', fn (Request $request) => [
            Limit::perHour(10)->by('booking:'.($request->user()?->id ?? $request->ip())),
            Limit::perDay(30)->by('booking-day:'.($request->user()?->id ?? $request->ip())),
        ]);

        /*
         * Advertising beacons.
         *
         * Far looser than the generic public budget because they are legitimately
         * chatty: one impression per slot per page, and a listings page carries
         * several. Sharing `api-public` would mean a visitor scrolling a long
         * result set exhausting their read budget on ad accounting and getting
         * throttled out of the marketplace itself.
         *
         * Still capped, and by IP, because this is the endpoint someone would
         * point a script at to inflate a competitor's spend or their own
         * revenue share. The cap is the cheap half of that defence; the real
         * half is that impressions are aggregated and clicks keep an ip_hash,
         * so a flood is visible in the data rather than merely rejected.
         */
        RateLimiter::for('ad-beacon', fn (Request $request) => Limit::perMinute(120)
            ->by('ad:'.($request->user()?->id ?? $request->ip())));
    }
}
