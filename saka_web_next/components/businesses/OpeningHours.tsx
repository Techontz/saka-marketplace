const DAYS: [string, string][] = [
  ["mon", "Monday"],
  ["tue", "Tuesday"],
  ["wed", "Wednesday"],
  ["thu", "Thursday"],
  ["fri", "Friday"],
  ["sat", "Saturday"],
  ["sun", "Sunday"],
];

/**
 * Opening hours.
 *
 * The API distinguishes three states and so does this: hours never configured
 * (the whole panel is hidden), a day with no shifts (Closed), and a day with
 * one or more shifts — split shifts included, because businesses close for
 * lunch.
 */
export function OpeningHours({
  hours,
}: {
  hours: Record<string, { open: string; close: string }[]> | null;
}) {
  if (!hours || Object.keys(hours).length === 0) return null;

  const today = DAYS[(new Date().getDay() + 6) % 7][0];

  return (
    <div className="rounded-xl border border-border bg-white p-6">
      <h3 className="mb-4 text-lg font-bold text-navy">Opening hours</h3>
      <ul className="space-y-2 text-sm">
        {DAYS.map(([key, label]) => {
          const shifts = hours[key];
          const isToday = key === today;

          return (
            <li
              key={key}
              className={`flex items-center justify-between gap-4 ${isToday ? "font-semibold text-navy" : "text-muted-foreground"}`}
            >
              <span>{label}</span>
              <span className="text-right">
                {shifts === undefined
                  ? "—"
                  : shifts.length === 0
                    ? "Closed"
                    : shifts.map((shift) => `${shift.open}–${shift.close}`).join(", ")}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
