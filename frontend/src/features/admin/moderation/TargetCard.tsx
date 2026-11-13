import { Link } from 'react-router';
import { Button } from '@/components/ui/Button';
import { Cover } from '@/components/ui/Media';
import { reportTargetPath, when } from './helpers';
import type { useDecisionDialog } from './ModerationDialogs';
import type { ReportTargetItem } from './moderationApi';
import { REASON_LABEL, TARGET_LABEL } from './moderationLabels';
import { Badge, Card, ModerationBadge } from './parts';

export function TargetCard({
  t,
  onAsk,
  detail = true,
}: {
  t: ReportTargetItem;
  onAsk: ReturnType<typeof useDecisionDialog>['ask'];
  detail?: boolean;
}) {
  const label = t.preview?.label ?? 'Contenu supprimé';
  const target = { type: t.targetType, id: t.targetId, label };
  const reasons = Object.entries(t.reasons).sort((a, b) => b[1] - a[1]);
  const live = t.preview !== null;
  return (
    <Card>
      <div className="flex gap-3">
        <Cover url={t.preview?.imageUrl ?? null} seed={t.targetId} className="size-14" />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <Badge>{TARGET_LABEL[t.targetType]}</Badge>
            <ModerationBadge moderation={t.moderation} />
            {t.suspended && <Badge tone="warning">Suspendu</Badge>}
            {!live && <Badge tone="warning">Supprimé</Badge>}
          </div>
          <p className="mt-1 line-clamp-2 font-semibold break-words">{label}</p>
          {t.preview?.owner && (
            <p className="text-muted text-sm">par @{t.preview.owner.username}</p>
          )}
        </div>
        <div className="text-right">
          <p className="text-xl leading-none font-semibold">{t.openReportCount}</p>
          <p className="text-muted text-xs">signalement{t.openReportCount > 1 ? 's' : ''}</p>
        </div>
      </div>
      <ul className="mt-3 flex flex-wrap gap-1.5" aria-label="Motifs">
        {reasons.map(([reason, n]) => (
          <li key={reason}>
            <Badge>
              {REASON_LABEL[reason] ?? reason} · {n}
            </Badge>
          </li>
        ))}
      </ul>
      <p className="text-muted mt-2 text-xs">
        Premier le {when(t.firstReportedAt)} · dernier le {when(t.lastReportedAt)}
      </p>
      <div className="mt-3 flex flex-wrap gap-2">
        {detail && (
          <Link
            to={reportTargetPath(t)}
            state={{ target: t }}
            className="border-fg text-fg hover:bg-surface-2 inline-flex h-9 items-center rounded-full border-[1.5px] px-3 text-sm font-semibold"
          >
            Voir les signalements
          </Link>
        )}
        {live && t.targetType !== 'user' && t.moderation !== 'hidden' && (
          <Button size="sm" variant="danger" onClick={() => onAsk(target, 'hide')}>
            Masquer
          </Button>
        )}
        {live && t.targetType !== 'user' && t.moderation === 'hidden' && (
          <Button size="sm" variant="secondary" onClick={() => onAsk(target, 'dismiss')}>
            Rétablir
          </Button>
        )}
        {live && t.targetType === 'user' && !t.suspended && (
          <Button size="sm" variant="danger" onClick={() => onAsk(target, 'suspend')}>
            Suspendre
          </Button>
        )}
        {t.openReportCount > 0 && (
          <Button size="sm" variant="ghost" onClick={() => onAsk(target, 'dismiss')}>
            Classer sans suite
          </Button>
        )}
      </div>
    </Card>
  );
}
