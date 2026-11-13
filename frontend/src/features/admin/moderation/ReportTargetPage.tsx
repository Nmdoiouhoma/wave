import { useQueryClient } from '@tanstack/react-query';
import { Link, useLocation, useParams } from 'react-router';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Avatar } from '@/components/ui/Media';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { useDecisionDialog } from './ModerationDialogs';
import type { ReportTargetItem, ReportTargetType } from './moderationApi';
import { REASON_LABEL, REPORT_STATUS_LABEL, TARGET_LABEL } from './moderationLabels';
import { when } from './helpers';
import { Badge, Card } from './parts';
import { TargetCard } from './TargetCard';
import { modKeys, useTargetReports } from './useModeration';

const TYPES = Object.keys(TARGET_LABEL);

export function ReportTargetPage() {
  const { targetType = '', targetId = '' } = useParams();
  const type = targetType as ReportTargetType;
  const location = useLocation();
  const qc = useQueryClient();
  const valid = TYPES.includes(targetType) && targetId !== '';
  const reports = useTargetReports(type, targetId);
  const dialog = useDecisionDialog();
  // No GET for a single target: the card comes from the queue (link state), else the last decision.
  const target =
    qc.getQueryData<ReportTargetItem>(modKeys.target(type, targetId)) ??
    (location.state as { target?: ReportTargetItem } | null)?.target ??
    null;
  const items = reports.data?.pages.flatMap((p) => p.items) ?? [];

  return (
    <section className="flex w-full max-w-3xl flex-col gap-4">
      <Link to="/admin/reports" className="text-muted text-sm hover:underline">
        ← Retour à la file
      </Link>
      <h1 className="text-2xl font-semibold">
        {valid ? `Signalements · ${TARGET_LABEL[type]}` : 'Cible inconnue'}
      </h1>
      {target ? (
        <ul>
          <TargetCard t={target} onAsk={dialog.ask} detail={false} />
        </ul>
      ) : (
        valid && (
          <p className="text-muted text-sm">
            Aperçu indisponible hors de la file. Identifiant : {targetId}
          </p>
        )
      )}
      {!valid ? (
        <EmptyState title="Cette cible n’existe pas" />
      ) : reports.isPending ? (
        <div className="flex justify-center py-10">
          <Spinner label="Chargement" />
        </div>
      ) : reports.isError ? (
        <ErrorState error={reports.error} onRetry={() => reports.refetch()} />
      ) : items.length === 0 ? (
        <EmptyState title="Aucun signalement" />
      ) : (
        <ul className="flex flex-col gap-3">
          {items.map((r) => (
            <Card key={r.id}>
              <div className="flex items-center gap-3">
                <Avatar
                  url={r.reporter.avatarUrl}
                  name={r.reporter.displayName}
                  seed={r.reporter.id}
                  className="size-9 text-xs"
                />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold">@{r.reporter.username}</p>
                  <p className="text-muted text-xs">{when(r.createdAt)}</p>
                </div>
                <Badge tone={r.status === 'open' ? 'warning' : 'neutral'}>
                  {REPORT_STATUS_LABEL[r.status]}
                </Badge>
              </div>
              <p className="mt-2 text-sm font-medium">{REASON_LABEL[r.reason] ?? r.reason}</p>
              {r.details && <p className="text-muted mt-1 text-sm break-words">{r.details}</p>}
              {r.handledBy && (
                <p className="text-muted mt-2 text-xs">
                  Traité par @{r.handledBy.username}
                  {r.handledAt ? ` le ${when(r.handledAt)}` : ''}
                  {r.decisionNote ? ` · « ${r.decisionNote} »` : ''}
                </p>
              )}
            </Card>
          ))}
        </ul>
      )}
      <InfiniteSentinel
        hasMore={!!reports.hasNextPage}
        loading={reports.isFetchingNextPage}
        onLoadMore={() => reports.fetchNextPage()}
      />
      {dialog.node}
    </section>
  );
}
