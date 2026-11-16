// W-83 (FB-31): evolution charts of the admin dashboard. Hand-rolled SVG (no chart library, 0 kB added);
// lives in the lazy DashboardPage chunk. Colour is never alone: legend + dash pattern + table fallback.
import { useState } from 'react';
import { AdminError } from './AdminError';
import { useAdminSeries } from './queries';
import type { AdminDashboardSeries, SeriesRange } from './adminApi';

interface Line {
  key: string;
  label: string;
  color: string;
  values: number[];
}
const DASHES = ['', '6 3', '2 3', '8 3 2 3'];
const W = 320;
const H = 140;
const M = { l: 30, r: 8, t: 8, b: 20 };

const dayFmt = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', timeZone: 'UTC' });
const dayLong = new Intl.DateTimeFormat('fr-FR', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  timeZone: 'UTC',
});
// `days` are local calendar dates: parse as UTC midnight and format in UTC so no shift occurs.
const parse = (d: string) => new Date(`${d}T00:00:00Z`);

function niceMax(v: number): number {
  if (v <= 4) return 4;
  const s = Math.max(2, 10 ** Math.floor(Math.log10(v)));
  return Math.ceil(v / s) * s;
}

function SeriesChart({ title, days, lines }: { title: string; days: string[]; lines: Line[] }) {
  const [hover, setHover] = useState<number | null>(null);
  const total = lines.reduce((n, l) => n + l.values.reduce((a, b) => a + b, 0), 0);
  const max = niceMax(Math.max(0, ...lines.flatMap((l) => l.values)));
  const n = days.length;
  const x = (i: number) => M.l + (n <= 1 ? 0 : (i * (W - M.l - M.r)) / (n - 1));
  const y = (v: number) => H - M.b - (v / max) * (H - M.t - M.b);
  const ticks = [0, max / 2, max];
  const xl = [0, Math.floor((n - 1) / 2), n - 1];
  return (
    <section className="bg-surface border-line rounded-[20px] border p-5" aria-label={title}>
      <h3 className="text-muted mb-3 text-xs font-semibold tracking-wide uppercase">{title}</h3>
      {total === 0 ? (
        <p className="text-muted py-8 text-center text-sm">Aucune donnée sur la période.</p>
      ) : (
        <>
          <ul className="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
            {lines.map((l, i) => (
              <li key={l.key} className="flex items-center gap-1.5">
                <svg width="22" height="8" aria-hidden="true">
                  <line x1="1" x2="21" y1="4" y2="4" stroke={l.color} strokeWidth="2" strokeDasharray={DASHES[i]} />
                </svg>
                {l.label}
              </li>
            ))}
          </ul>
          <div className="relative">
            <svg viewBox={`0 0 ${W} ${H}`} className="w-full" aria-hidden="true" onMouseLeave={() => setHover(null)}>
              {ticks.map((t) => (
                <g key={t}>
                  <line x1={M.l} x2={W - M.r} y1={y(t)} y2={y(t)} stroke="var(--line)" strokeWidth="1" />
                  <text x={M.l - 4} y={y(t) + 3} textAnchor="end" fontSize="9" fill="var(--muted)">
                    {Math.round(t)}
                  </text>
                </g>
              ))}
              {xl.map((i, k) => (
                <text
                  key={i}
                  x={x(i)}
                  y={H - 5}
                  fontSize="9"
                  fill="var(--muted)"
                  textAnchor={k === 0 ? 'start' : k === 2 ? 'end' : 'middle'}
                >
                  {dayFmt.format(parse(days[i]!))}
                </text>
              ))}
              {hover !== null && (
                <line x1={x(hover)} x2={x(hover)} y1={M.t} y2={H - M.b} stroke="var(--muted)" strokeWidth="1" />
              )}
              {lines.map((l, li) => (
                <g key={l.key}>
                  <polyline
                    fill="none"
                    stroke={l.color}
                    strokeWidth="2"
                    strokeLinejoin="round"
                    strokeDasharray={DASHES[li]}
                    points={l.values.map((v, i) => `${x(i)},${y(v)}`).join(' ')}
                  />
                  <circle cx={x(hover ?? n - 1)} cy={y(l.values[hover ?? n - 1] ?? 0)} r="3.5" fill={l.color} stroke="var(--surface)" strokeWidth="2" />
                </g>
              ))}
              {days.map((d, i) => (
                <rect
                  key={d}
                  x={x(i) - (W - M.l - M.r) / Math.max(n - 1, 1) / 2}
                  y={M.t}
                  width={(W - M.l - M.r) / Math.max(n - 1, 1)}
                  height={H - M.t - M.b}
                  fill="transparent"
                  onMouseEnter={() => setHover(i)}
                />
              ))}
            </svg>
            {hover !== null && (
              <p className="text-fg bg-surface-2 mt-1 rounded-lg px-2 py-1 text-xs" aria-hidden="true">
                {dayLong.format(parse(days[hover]!))} · {lines.map((l) => `${l.label} ${l.values[hover]}`).join(' · ')}
              </p>
            )}
          </div>
          <table className="sr-only">
            <caption>{title}</caption>
            <thead>
              <tr>
                <th scope="col">Jour</th>
                {lines.map((l) => (
                  <th key={l.key} scope="col">
                    {l.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {days.map((d, i) => (
                <tr key={d}>
                  <th scope="row">{dayLong.format(parse(d))}</th>
                  {lines.map((l) => (
                    <td key={l.key}>{l.values[i]}</td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}
    </section>
  );
}

const c = (i: number) => `var(--viz-${i})`;

export function buildGroups(d: AdminDashboardSeries) {
  const groups: { title: string; lines: Line[] }[] = [];
  if (d.activity)
    groups.push({
      title: 'Inscriptions et connexions',
      lines: [
        { key: 'registrations', label: 'Inscriptions', color: c(1), values: d.activity.registrations },
        { key: 'logins', label: 'Connexions', color: c(2), values: d.activity.logins },
      ],
    });
  if (d.content)
    groups.push({
      title: 'Contenus publiés',
      lines: [
        { key: 'tracks', label: 'Morceaux', color: c(1), values: d.content.tracks },
        { key: 'posts', label: 'Posts', color: c(2), values: d.content.posts },
        { key: 'comments', label: 'Commentaires', color: c(3), values: d.content.comments },
        { key: 'rejected', label: 'Envois refusés', color: c(4), values: d.content.rejectedUploads },
      ],
    });
  groups.push({
    title: 'Signalements et masquages',
    lines: [
      { key: 'reports', label: 'Signalements', color: c(1), values: d.moderation.reports },
      { key: 'hidden', label: 'Masquages', color: c(2), values: d.moderation.hidden },
    ],
  });
  if (d.alerts)
    groups.push({
      title: 'Alertes par gravité',
      lines: [
        { key: 'high', label: 'Haute', color: 'var(--viz-high)', values: d.alerts.high },
        { key: 'medium', label: 'Moyenne', color: 'var(--viz-medium)', values: d.alerts.medium },
        { key: 'low', label: 'Basse', color: 'var(--viz-low)', values: d.alerts.low },
      ],
    });
  return groups;
}

export function SeriesCharts() {
  const [range, setRange] = useState<SeriesRange>('7d');
  const q = useAdminSeries(range);
  return (
    <section className="flex flex-col gap-4" aria-label="Évolution">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-bold tracking-tight">Évolution</h2>
        <div role="group" aria-label="Période" className="bg-surface-2 flex rounded-full p-0.5 text-sm">
          {(['7d', '30d'] as const).map((r) => (
            <button
              key={r}
              type="button"
              aria-pressed={range === r}
              onClick={() => setRange(r)}
              className={`rounded-full px-3 py-1 ${range === r ? 'bg-surface font-semibold' : 'text-muted'}`}
            >
              {r === '7d' ? '7 j' : '30 j'}
            </button>
          ))}
        </div>
      </div>
      {q.isPending ? (
        <p className="text-muted text-sm" role="status">
          Chargement des graphiques…
        </p>
      ) : q.isError ? (
        <AdminError error={q.error} onRetry={() => void q.refetch()} />
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {buildGroups(q.data).map((g) => (
            <SeriesChart key={g.title} title={g.title} days={q.data.days} lines={g.lines} />
          ))}
        </div>
      )}
    </section>
  );
}
