// W-89 (FB-34): supervision panel at the top of the admin dashboard. Hand-rolled SVG (no chart library);
// lives in the lazy DashboardPage chunk. Colour is never alone: every level shows an icon + a label in
// full, and the histogram has a visually hidden table fallback. Text keeps the text colours.
import { useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import { AlertIcon, CheckIcon, CriticalIcon, WarningIcon } from '@/components/ui/icons';
import { AdminError } from './AdminError';
import type { SupervisionHour, SupervisionLevel, SupervisionSignal } from './adminApi';
import { alertsApi, RULE_LABEL, SEVERITY_LABEL, type AlertSeverity } from './alerts/alertsApi';
import { SUPERVISION_POLL_MS, useAdminSupervision } from './queries';

const LEVELS: SupervisionLevel[] = ['ok', 'attention', 'alerte', 'critique'];
const LEVEL_LABEL: Record<SupervisionLevel, string> = {
  ok: 'Normal',
  attention: 'Attention',
  alerte: 'Alerte',
  critique: 'Critique',
};
const LEVEL_ICON = {
  ok: CheckIcon,
  attention: AlertIcon,
  alerte: WarningIcon,
  critique: CriticalIcon,
};
const GLOBAL_TEXT: Record<SupervisionLevel, string> = {
  ok: 'Aucun signal ne demande ton attention.',
  attention: 'Au moins un signal est à surveiller.',
  alerte: 'Au moins un signal est en alerte.',
  critique: 'Au moins un signal est critique : à traiter maintenant.',
};
const SIGNAL_TITLE: Record<SupervisionSignal['key'], string> = {
  suspicious: 'Activité suspecte',
  logins: 'Connexions',
  abuse: 'Abus et limites',
  uploads: 'Envois de fichiers',
};
// Same mapping as the backend rollup (supervision brief): high → critique, medium → alerte, low → attention.
const SEVERITY_LEVEL: Record<AlertSeverity, SupervisionLevel> = {
  high: 'critique',
  medium: 'alerte',
  low: 'attention',
};
const fill = (l: SupervisionLevel) => `var(--status-${l})`;

const TZ = 'Europe/Paris';
const hourPart = new Intl.DateTimeFormat('fr-FR', {
  hour: 'numeric',
  hourCycle: 'h23',
  timeZone: TZ,
});
const dayPart = new Intl.DateTimeFormat('fr-FR', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  timeZone: TZ,
});
// ICU does not always append the "h" unit in French: read the hour part and write the unit ourselves.
const hourOf = (iso: string) =>
  `${hourPart.formatToParts(new Date(iso)).find((p) => p.type === 'hour')?.value ?? ''} h`;
const hourLong = (iso: string) => `${dayPart.format(new Date(iso))}, ${hourOf(iso)}`;
const timeFmt = new Intl.DateTimeFormat('fr-FR', { timeStyle: 'short', timeZone: TZ });
const dateTimeFmt = new Intl.DateTimeFormat('fr-FR', {
  dateStyle: 'medium',
  timeStyle: 'short',
  timeZone: TZ,
});
const int = (n: number) => n.toLocaleString('fr-FR');
const events = (n: number) => `${int(n)} événement${n > 1 ? 's' : ''}`;

/** Status-coloured square holding a dark icon (fixed ink, readable on the four fills in both themes) + label. */
function LevelBadge({
  level,
  label,
  size = 20,
}: {
  level: SupervisionLevel;
  label?: string;
  size?: number;
}) {
  const Icon = LEVEL_ICON[level];
  return (
    <span
      data-level={level}
      className="text-fg inline-flex items-center gap-1.5 text-sm font-semibold"
    >
      <span
        className="inline-flex shrink-0 items-center justify-center rounded-md text-[#16161a]"
        style={{ background: fill(level), width: size, height: size }}
      >
        <Icon size={Math.round(size * 0.7)} strokeWidth={2.5} />
      </span>
      {label ?? LEVEL_LABEL[level]}
    </span>
  );
}

function Box({
  title,
  children,
  className = '',
}: {
  title: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section
      className={`bg-surface border-line rounded-[20px] border p-5 ${className}`}
      aria-label={title}
    >
      <h3 className="text-muted mb-3 text-xs font-semibold tracking-wide uppercase">{title}</h3>
      {children}
    </section>
  );
}

function Tile({ signal }: { signal: SupervisionSignal }) {
  const pct = Math.round(Math.min(Math.max(signal.value, 0), 1) * 100);
  return (
    <Box title={SIGNAL_TITLE[signal.key]}>
      <LevelBadge level={signal.level} />
      {signal.key === 'logins' && !signal.measured ? (
        <p className="text-muted mt-2 text-sm">Trop peu de connexions pour évaluer</p>
      ) : (
        <p className="mt-2 text-2xl font-bold tabular-nums">
          {signal.key === 'logins' ? `${pct} %` : int(signal.value)}
        </p>
      )}
      <p className="text-muted text-xs">{signal.detail}</p>
      {signal.key === 'logins' && signal.measured && (
        // Failure-rate gauge; ticks at the backend thresholds (`thresholds`, fractions). The figure above carries the value.
        <div
          className="bg-surface-2 relative mt-3 h-2 overflow-hidden rounded-full"
          aria-hidden="true"
        >
          <div
            className="h-full rounded-full"
            style={{ width: `${pct}%`, background: fill(signal.level) }}
          />
          {(signal.thresholds ?? []).map((t) => (
            <span
              key={t}
              className="bg-muted absolute top-0 h-full w-px"
              style={{ left: `${t * 100}%` }}
            />
          ))}
        </div>
      )}
      {signal.key === 'suspicious' && signal.severities && (
        <ul className="mt-3 flex flex-wrap gap-1.5 text-xs">
          {(['high', 'medium', 'low'] as const)
            .map((sev) => [sev, signal.severities![sev]] as const)
            .map(([sev, n]) => (
              <li
                key={sev}
                className="bg-surface-2 flex items-center gap-1.5 rounded-full px-2 py-0.5"
              >
                <span
                  className="size-2 rounded-full"
                  style={{ background: fill(SEVERITY_LEVEL[sev]) }}
                  aria-hidden="true"
                />
                {SEVERITY_LABEL[sev]} <span className="font-semibold tabular-nums">{int(n)}</span>
              </li>
            ))}
        </ul>
      )}
    </Box>
  );
}

const W = 640;
const H = 170;
const M = { l: 34, r: 6, t: 8, b: 20 };

function niceMax(v: number): number {
  if (v <= 4) return 4;
  const s = Math.max(2, 10 ** Math.floor(Math.log10(v)));
  return Math.ceil(v / s) * s;
}

function Histogram({ hours }: { hours: SupervisionHour[] }) {
  const [hover, setHover] = useState<number | null>(null);
  const title = 'Événements notables par heure (24 h)';
  const total = hours.reduce((n, h) => n + h.count, 0);
  const max = niceMax(Math.max(0, ...hours.map((h) => h.count)));
  const slot = (W - M.l - M.r) / Math.max(hours.length, 1);
  const y = (v: number) => H - M.b - (v / max) * (H - M.t - M.b);
  const hovered = hover === null ? undefined : hours[hover];
  return (
    <Box title={title} className="md:col-span-2">
      {total === 0 ? (
        <p className="text-muted py-8 text-center text-sm">
          Aucun événement notable sur les 24 dernières heures.
        </p>
      ) : (
        <>
          <svg
            viewBox={`0 0 ${W} ${H}`}
            className="w-full"
            aria-hidden="true"
            onMouseLeave={() => setHover(null)}
          >
            {[0, max / 2, max].map((t) => (
              <g key={t}>
                <line
                  x1={M.l}
                  x2={W - M.r}
                  y1={y(t)}
                  y2={y(t)}
                  stroke="var(--line)"
                  strokeWidth="1"
                />
                <text x={M.l - 5} y={y(t) + 3} textAnchor="end" fontSize="10" fill="var(--muted)">
                  {Math.round(t)}
                </text>
              </g>
            ))}
            {hours.map((h, i) => {
              const top = h.count > 0 ? Math.min(y(h.count), y(0) - 2) : y(0);
              return (
                <g key={h.hourStart} onMouseEnter={() => setHover(i)}>
                  {/* Full-height transparent hit area so empty hours also get a tooltip. */}
                  <rect
                    x={M.l + i * slot}
                    y={M.t}
                    width={slot}
                    height={H - M.t - M.b}
                    fill="transparent"
                  />
                  <rect
                    data-testid="supervision-bar"
                    x={M.l + i * slot + 2}
                    y={top}
                    width={slot - 4}
                    height={y(0) - top}
                    rx="2"
                    fill={fill(h.level)}
                    opacity={hover === null || hover === i ? 1 : 0.55}
                  />
                  {i % 6 === 0 && (
                    <text
                      x={M.l + i * slot + slot / 2}
                      y={H - 5}
                      fontSize="10"
                      fill="var(--muted)"
                      textAnchor="middle"
                    >
                      {hourOf(h.hourStart)}
                    </text>
                  )}
                </g>
              );
            })}
          </svg>
          <p
            className="text-fg bg-surface-2 mt-1 min-h-6 rounded-lg px-2 py-1 text-xs"
            aria-hidden="true"
          >
            {hovered
              ? `${hourLong(hovered.hourStart)} · ${events(hovered.count)} · ${LEVEL_LABEL[hovered.level]}`
              : 'Survole une barre pour le détail de l’heure (heure de Paris).'}
          </p>
          <table className="sr-only">
            <caption>{title}, heure de Paris</caption>
            <thead>
              <tr>
                <th scope="col">Heure</th>
                <th scope="col">Événements</th>
                <th scope="col">Niveau</th>
              </tr>
            </thead>
            <tbody>
              {hours.map((h) => (
                <tr key={h.hourStart}>
                  <th scope="row">{hourLong(h.hourStart)}</th>
                  <td>{h.count}</td>
                  <td>{LEVEL_LABEL[h.level]}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}
      <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5" aria-label="Légende des niveaux">
        {LEVELS.map((l) => (
          <li key={l}>
            <LevelBadge level={l} size={16} />
          </li>
        ))}
      </ul>
    </Box>
  );
}

/** Reuses the existing `GET /admin/alerts` (W-75), first page of open alerts; no new endpoint. */
function OpenAlerts() {
  const q = useQuery({
    queryKey: ['admin', 'alerts', { status: 'open', scope: 'supervision' }],
    queryFn: () => alertsApi.list({ status: 'open' }, null),
    refetchInterval: SUPERVISION_POLL_MS,
    refetchIntervalInBackground: false,
    retry: false,
  });
  const items = q.data?.items.slice(0, 5) ?? [];
  return (
    <Box title="Alertes ouvertes">
      {q.isPending ? (
        <p className="text-muted text-sm" role="status">
          Chargement des alertes…
        </p>
      ) : !q.data ? (
        <p className="text-muted text-sm">Impossible de charger les alertes.</p>
      ) : items.length === 0 ? (
        <p className="text-muted text-sm">Aucune alerte ouverte.</p>
      ) : (
        <ul className="flex flex-col gap-3">
          {items.map((a) => (
            <li key={a.id} className="flex flex-col gap-0.5">
              <LevelBadge level={SEVERITY_LEVEL[a.severity]} label={RULE_LABEL[a.rule]} size={16} />
              <span className="text-muted text-xs">
                Gravité {SEVERITY_LABEL[a.severity].toLowerCase()} · {int(a.hitCount)} occurrence
                {a.hitCount > 1 ? 's' : ''} ·{' '}
                <time dateTime={a.lastSeenAt}>{dateTimeFmt.format(new Date(a.lastSeenAt))}</time>
              </span>
            </li>
          ))}
        </ul>
      )}
      <Link to="/admin/alerts" className="text-accent mt-3 inline-block text-sm hover:underline">
        Voir toutes les alertes
      </Link>
    </Box>
  );
}

export function SupervisionPanel() {
  const q = useAdminSupervision(true);
  const d = q.data;
  let body: ReactNode;
  if (!d) {
    body = q.isError ? (
      <AdminError error={q.error} onRetry={() => void q.refetch()} />
    ) : (
      <p className="text-muted text-sm" role="status">
        Chargement de la supervision…
      </p>
    );
  } else {
    // Moderator shape (null `global` / `hourly`, empty `signals`): the panel is hidden entirely.
    if (d.global === null || d.hourly === null || d.signals.length === 0) return null;
    body = (
      <>
        <div
          role="status"
          className="bg-surface border-line flex flex-wrap items-center gap-x-4 gap-y-1 rounded-[20px] border border-l-8 p-5"
          style={{ borderLeftColor: fill(d.global) }}
        >
          <LevelBadge level={d.global} label={`État global : ${LEVEL_LABEL[d.global]}`} size={28} />
          <p className="text-sm">{GLOBAL_TEXT[d.global]}</p>
          <p className="text-muted ml-auto text-xs">
            {q.isError ? 'Dernière actualisation en échec · ' : ''}
            Mis à jour à <time dateTime={d.updatedAt}>{timeFmt.format(new Date(d.updatedAt))}</time>
            , actualisé toutes les 30 secondes
          </p>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {d.signals.map((s) => (
            <Tile key={s.key} signal={s} />
          ))}
        </div>
        <div className="grid gap-4 md:grid-cols-3">
          <Histogram hours={d.hourly} />
          <OpenAlerts />
        </div>
      </>
    );
  }
  return (
    <section className="flex flex-col gap-4" aria-label="Supervision">
      <h2 className="text-lg font-bold tracking-tight">Supervision</h2>
      {body}
    </section>
  );
}
