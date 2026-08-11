/**
 * Who built this, and which build it is.
 *
 * SAKA is the client's product and stays the only brand on the page. The
 * developer credit is a one-line attribution in the footer's legal row and a
 * "technology partner" note on the About page — the same weight a print
 * publication gives its typesetter, not an advertisement.
 *
 * Everything here is env-overridable so a white-label deployment can remove or
 * replace the credit without a code change, and so the version comes from CI
 * rather than from someone remembering to edit a constant.
 */

function env(value: string | undefined, fallback: string): string {
  const trimmed = value?.trim();
  return trimmed ? trimmed : fallback;
}

export const DEVELOPER = {
  name: env(process.env.NEXT_PUBLIC_DEVELOPER_NAME, "TechOn Software LLC"),
  url: env(process.env.NEXT_PUBLIC_DEVELOPER_URL, "https://www.techon.co.tz"),
  supportEmail: env(process.env.NEXT_PUBLIC_DEVELOPER_SUPPORT, "contact@techontz.com"),
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
