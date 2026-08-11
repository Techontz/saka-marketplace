/**
 * Who built this, and which build it is.
 *
 * A copy of the marketplace's `lib/build-info.ts`. The three apps are separate
 * Next projects with no shared package, and this file has no dependencies —
 * duplicating it is cheaper than the alternative, which is the version number
 * in the admin portal disagreeing with the one in the footer.
 */

function env(value: string | undefined, fallback: string): string {
  const trimmed = value?.trim();
  return trimmed ? trimmed : fallback;
}

export const DEVELOPER = {
  name: env(process.env.NEXT_PUBLIC_DEVELOPER_NAME, "TechOn Software LLC"),
  url: env(process.env.NEXT_PUBLIC_DEVELOPER_URL, "https://techonsoftware.com"),
  supportEmail: env(process.env.NEXT_PUBLIC_DEVELOPER_SUPPORT, "support@techonsoftware.com"),
};

/**
 * Set `NEXT_PUBLIC_DEVELOPER_NAME=""` to remove the credit entirely.
 *
 * A blank string is the natural way to say "none" in a dotenv file, and it has
 * to actually work — see the identical reasoning in lib/config.ts, where a
 * blank value silently won and blanked every map on the site.
 */
export const SHOW_DEVELOPER_CREDIT = process.env.NEXT_PUBLIC_DEVELOPER_NAME?.trim() !== "";

/**
 * The application version.
 *
 * Read from the deploy environment. `dev` locally, so a screenshot from a
 * developer's machine is never mistaken for a release build.
 */
export const APP_VERSION = env(process.env.NEXT_PUBLIC_APP_VERSION, "dev");

export const BUILD_YEAR = env(process.env.NEXT_PUBLIC_BUILD_YEAR, "2026");
