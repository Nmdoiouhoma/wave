import { useState } from 'react';
import { SelectField } from '@/components/ui/Field';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { useDecisionDialog } from './ModerationDialogs';
import type { ReportStatus, ReportTargetType } from './moderationApi';
import { STATUS_LABEL, TARGET_LABEL } from './moderationLabels';
import { TargetCard } from './TargetCard';
import { useReportQueue } from './useModeration';

export function ReportQueuePage() {
  const [status, setStatus] = useState<ReportStatus>('open');
  const [type, setType] = useState<ReportTargetType | ''>('');
  const q = useReportQueue(status, type || undefined);
  const dialog = useDecisionDialog();
  const items = q.data?.pages.flatMap((p) => p.items) ?? [];

  return (
    <section className="flex w-full max-w-3xl flex-col gap-4">
      <h1 className="text-2xl font-semibold">Signalements</h1>
      <div className="grid gap-3 sm:grid-cols-2">
        <SelectField
          label="Statut"
          value={status}
          onChange={(e) => setStatus(e.target.value as ReportStatus)}
        >
          {(Object.keys(STATUS_LABEL) as ReportStatus[]).map((s) => (
            <option key={s} value={s}>
              {STATUS_LABEL[s]}
            </option>
          ))}
        </SelectField>
        <SelectField
          label="Type de cible"
          value={type}
          onChange={(e) => setType(e.target.value as ReportTargetType | '')}
        >
          <option value="">Tous</option>
          {(Object.keys(TARGET_LABEL) as ReportTargetType[]).map((k) => (
            <option key={k} value={k}>
              {TARGET_LABEL[k]}
            </option>
          ))}
        </SelectField>
      </div>
      {q.isPending ? (
        <div className="flex justify-center py-10">
          <Spinner label="Chargement des signalements" />
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : items.length === 0 ? (
        <EmptyState title="Rien à traiter">Aucune cible dans cette vue.</EmptyState>
      ) : (
        <ul className="flex flex-col gap-3">
          {items.map((t) => (
            <TargetCard key={`${t.targetType}:${t.targetId}`} t={t} onAsk={dialog.ask} />
          ))}
        </ul>
      )}
      <InfiniteSentinel
        hasMore={!!q.hasNextPage}
        loading={q.isFetchingNextPage}
        onLoadMore={() => q.fetchNextPage()}
      />
      {dialog.node}
    </section>
  );
}
