import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router';
import { isApiError } from '@/api/errors';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { SelectField } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Sheet } from '@/components/ui/Sheet';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/States';
import { errorMessage } from '@/lib/errorMessages';
import { toast } from '@/stores/toast';
import { AdminError } from '../AdminError';
import { DialogForm } from '../moderation/DialogForm';
import { Badge } from '../moderation/parts';
import {
  alertsApi,
  RULE_LABEL,
  SEVERITY_LABEL,
  STATUS_LABEL,
  type AlertRule,
  type AlertSeverity,
  type AlertStatus,
  type SecurityAlert,
} from './alertsApi';

const dateTime = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' });
const TONE = { low: 'neutral', medium: 'warning', high: 'danger' } as const;

function ackError(e: unknown): string {
  if (isApiError(e) && e.code === 'already_acknowledged')
    return 'Cette alerte a déjà été acquittée, recharge la liste.';
  if (isApiError(e) && e.status === 404) return 'Cette alerte n’existe plus.';
  if (isApiError(e) && e.status === 422) return 'Note invalide (1 à 500 caractères).';
  return errorMessage(e);
}

function AlertCard({ a, onAck }: { a: SecurityAlert; onAck: (a: SecurityAlert) => void }) {
  return (
    <li className="bg-surface border-line flex flex-col gap-2 rounded-2xl border p-4">
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={TONE[a.severity]}>{SEVERITY_LABEL[a.severity]}</Badge>
        <span className="font-medium">{RULE_LABEL[a.rule]}</span>
        <span className="text-muted text-sm">
          {a.hitCount} occurrence{a.hitCount > 1 ? 's' : ''}
        </span>
      </div>
      <p className="text-muted text-sm">
        {a.subjectUser ? (
          <Link to={`/admin/users/${a.subjectUser.id}`} className="text-accent hover:underline">
            @{a.subjectUser.username}
          </Link>
        ) : a.subjectIpHash ? (
          <span>Adresse IP anonymisée</span>
        ) : a.subjectTarget ? (
          <span>
            Cible {a.subjectTarget.targetType} {a.subjectTarget.targetId}
          </span>
        ) : (
          <span>Sujet inconnu</span>
        )}
        {' · '}
        <time dateTime={a.firstSeenAt}>{dateTime.format(new Date(a.firstSeenAt))}</time>
        {' → '}
        <time dateTime={a.lastSeenAt}>{dateTime.format(new Date(a.lastSeenAt))}</time>
      </p>
      {a.status === 'acknowledged' ? (
        <p className="text-sm">
          <Badge tone="success">Acquittée</Badge>{' '}
          {a.acknowledgedBy && <span>par @{a.acknowledgedBy.username}</span>}
          {a.acknowledgedAt && (
            <span className="text-muted"> le {dateTime.format(new Date(a.acknowledgedAt))}</span>
          )}
          {a.note && <span className="block break-words">Note : {a.note}</span>}
        </p>
      ) : (
        <div>
          <Button variant="secondary" onClick={() => onAck(a)}>
            Acquitter
          </Button>
        </div>
      )}
    </li>
  );
}

export function AlertsPage() {
  const [status, setStatus] = useState<AlertStatus>('open');
  const [severity, setSeverity] = useState<AlertSeverity | ''>('');
  const [rule, setRule] = useState<AlertRule | ''>('');
  const [asking, setAsking] = useState<SecurityAlert | null>(null);
  const qc = useQueryClient();
  const q = useInfiniteQuery({
    queryKey: ['admin', 'alerts', { status, severity, rule }],
    queryFn: ({ pageParam }) =>
      alertsApi.list(
        { status, severity: severity || undefined, rule: rule || undefined },
        pageParam,
      ),
    initialPageParam: null as string | null,
    getNextPageParam: (l) => l.nextCursor,
  });
  const ack = useMutation({
    mutationFn: ({ id, note }: { id: string; note: string }) => alertsApi.acknowledge(id, note),
    onSuccess: () => {
      toast('Alerte acquittée.', 'success');
      setAsking(null);
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['admin', 'alerts'] }),
  });
  const items = q.data?.pages.flatMap((p) => p.items) ?? [];

  return (
    <section className="flex w-full max-w-3xl flex-col gap-4">
      <h1 className="text-2xl font-semibold">Alertes de sécurité</h1>
      <div className="grid gap-3 sm:grid-cols-3">
        <SelectField
          label="Statut"
          value={status}
          onChange={(e) => setStatus(e.target.value as AlertStatus)}
        >
          {(Object.keys(STATUS_LABEL) as AlertStatus[]).map((s) => (
            <option key={s} value={s}>
              {STATUS_LABEL[s]}
            </option>
          ))}
        </SelectField>
        <SelectField
          label="Gravité"
          value={severity}
          onChange={(e) => setSeverity(e.target.value as AlertSeverity | '')}
        >
          <option value="">Toutes</option>
          {(Object.keys(SEVERITY_LABEL) as AlertSeverity[]).map((s) => (
            <option key={s} value={s}>
              {SEVERITY_LABEL[s]}
            </option>
          ))}
        </SelectField>
        <SelectField
          label="Règle"
          value={rule}
          onChange={(e) => setRule(e.target.value as AlertRule | '')}
        >
          <option value="">Toutes</option>
          {(Object.keys(RULE_LABEL) as AlertRule[]).map((r) => (
            <option key={r} value={r}>
              {RULE_LABEL[r]}
            </option>
          ))}
        </SelectField>
      </div>
      {q.isPending ? (
        <div className="flex justify-center py-10">
          <Spinner label="Chargement des alertes" />
        </div>
      ) : q.isError ? (
        <AdminError error={q.error} onRetry={() => void q.refetch()} />
      ) : items.length === 0 ? (
        <EmptyState title="Aucune alerte">Rien à signaler dans cette vue.</EmptyState>
      ) : (
        <ul className="flex flex-col gap-3">
          {items.map((a) => (
            <AlertCard key={a.id} a={a} onAck={setAsking} />
          ))}
        </ul>
      )}
      <InfiniteSentinel
        hasMore={!!q.hasNextPage}
        loading={q.isFetchingNextPage}
        onLoadMore={() => void q.fetchNextPage()}
      />
      <Sheet open={!!asking} title="Acquitter l’alerte" onClose={() => setAsking(null)}>
        {asking && (
          <DialogForm
            description={`${RULE_LABEL[asking.rule]} : une nouvelle occurrence ouvrira une nouvelle alerte.`}
            confirm="Acquitter"
            danger={false}
            noteLabel="Note (obligatoire)"
            noteRequired
            pending={ack.isPending}
            error={ack.isError ? ackError(ack.error) : null}
            onSubmit={(note) => ack.mutate({ id: asking.id, note })}
            onCancel={() => setAsking(null)}
          />
        )}
      </Sheet>
    </section>
  );
}
