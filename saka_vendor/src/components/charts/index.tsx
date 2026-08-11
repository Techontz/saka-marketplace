"use client";

import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import type { DailyPoint } from "@/lib/api/types";

/**
 * Charts for the dashboard and analytics screens.
 *
 * Every series the API returns is already gap-filled to one point per day, so
 * nothing here has to reason about missing dates — a chart that silently
 * interpolates across a gap reads as steady traffic rather than none.
 */

const AXIS = { fontSize: 11, fill: "var(--color-ink-faint)" };

/** "2026-07-21" -> "21 Jul". Full dates on a 30-point axis are unreadable. */
function shortDate(value: string): string {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString(undefined, { day: "numeric", month: "short" });
}

function ChartTooltip({
  active,
  payload,
  label,
}: {
  active?: boolean;
  payload?: { value?: number; name?: string }[];
  label?: string;
}) {
  if (!active || !payload?.length) return null;

  return (
    <div className="rounded-[var(--radius-control)] border border-line bg-surface px-3 py-2 shadow-lg">
      <p className="text-[11px] text-ink-faint">{label ? shortDate(label) : ""}</p>
      {payload.map((entry, index) => (
        <p key={index} className="text-sm font-medium text-ink">
          {(entry.value ?? 0).toLocaleString()}
          {entry.name ? <span className="ml-1 text-xs text-ink-soft">{entry.name}</span> : null}
        </p>
      ))}
    </div>
  );
}

export function TrendChart({
  data,
  label,
  color = "var(--color-brand)",
  height = 220,
}: {
  data: DailyPoint[];
  label: string;
  color?: string;
  height?: number;
}) {
  const gradientId = `grad-${label.replace(/\W/g, "")}`;

  return (
    <ResponsiveContainer width="100%" height={height}>
      <AreaChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: -18 }}>
        <defs>
          <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={color} stopOpacity={0.22} />
            <stop offset="100%" stopColor={color} stopOpacity={0} />
          </linearGradient>
        </defs>
        <CartesianGrid stroke="var(--color-line)" vertical={false} />
        <XAxis
          dataKey="date"
          tickFormatter={shortDate}
          tick={AXIS}
          tickLine={false}
          axisLine={false}
          // Roughly six labels whatever the range, so a 365-day chart does not
          // render an unreadable smear of dates.
          interval={Math.max(0, Math.floor(data.length / 6) - 1)}
        />
        <YAxis tick={AXIS} tickLine={false} axisLine={false} allowDecimals={false} width={44} />
        <Tooltip content={<ChartTooltip />} />
        <Area
          type="monotone"
          dataKey="value"
          name={label}
          stroke={color}
          strokeWidth={2}
          fill={`url(#${gradientId})`}
          // No dots on a 30+ point series; they turn the line into noise.
          dot={false}
          activeDot={{ r: 3 }}
        />
      </AreaChart>
    </ResponsiveContainer>
  );
}

export function CategoryBarChart({
  data,
  height = 260,
}: {
  data: { name: string; listings: number }[];
  height?: number;
}) {
  return (
    <ResponsiveContainer width="100%" height={height}>
      {/* Horizontal bars: category names are words, and vertical bars would
          rotate them to 45 degrees and make them hard to read. */}
      <BarChart data={data} layout="vertical" margin={{ top: 4, right: 16, bottom: 4, left: 8 }}>
        <CartesianGrid stroke="var(--color-line)" horizontal={false} />
        <XAxis type="number" tick={AXIS} tickLine={false} axisLine={false} allowDecimals={false} />
        <YAxis
          type="category"
          dataKey="name"
          tick={AXIS}
          tickLine={false}
          axisLine={false}
          width={92}
        />
        <Tooltip content={<ChartTooltip />} cursor={{ fill: "var(--color-muted-soft)" }} />
        <Bar dataKey="listings" name="listings" radius={[0, 4, 4, 0]}>
          {data.map((_, index) => (
            // One hue, stepped in lightness: distinct bars without implying the
            // categories belong to different groups.
            <Cell key={index} fill={`oklch(${0.5 + index * 0.035} 0.13 250)`} />
          ))}
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  );
}
