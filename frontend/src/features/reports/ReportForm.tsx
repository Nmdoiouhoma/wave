// W-36: report form (reason + optional details), embeddable in any sheet.
import { useMutation } from '@tanstack/react-query';
import { useId, useState } from 'react';
import { isApiError } from '@/api/errors';
import { api } from '@/api/instance';
import type { ReportReason } from '@/api/types';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextArea } from '@/components/ui/Field';
import { useVerifiedGate } from '@/features/auth/useVerifiedGate';
import { errorMessage, retryHint } from '@/lib/errorMessages';
import { toast } from '@/stores/toast';
import { DETAILS_MAX, REPORT_REASONS, type ReportTarget } from './reportOptions';

/** Outcome messages that close the form (the report is settled one way or another). */
function settled(e: unknown): string | null {
  if (!isApiError(e)) return null;
  if (e.status === 409 || e.code === 'already_reported') return 'Tu as déjà signalé ce contenu';
  if (e.status === 404) return 'Ce contenu n’est plus disponible';
  return null;
}

function inlineError(e: unknown): string {
  if (isApiError(e)) {
    if (e.code === 'cannot_report_own') return 'Tu ne peux pas signaler ton propre contenu.';
    if (e.code === 'rate_limited')
      return `Trop de signalements envoyés. ${retryHint(e.retryAfter)}`;
  }
  return errorMessage(e);
}

export function ReportForm({ target, onDone }: { target: ReportTarget; onDone: () => void }) {
  const [reason, setReason] = useState<ReportReason | null>(null);
  const [details, setDetails] = useState('');
  const gate = useVerifiedGate('signaler un contenu');
  const legendId = useId();
  const mutation = useMutation({
    mutationFn: () =>
      api.reports.create({
        targetType: target.type,
        targetId: target.id,
        reason: reason!,
        details: details.trim() || null,
      }),
    onSuccess: () => {
      toast('Merci, on regarde', 'success');
      onDone();
    },
    onError: (e) => {
      const msg = settled(e);
      if (msg) {
        toast(msg);
        onDone();
      }
    },
  });
  const tooLong = details.length > DETAILS_MAX;
  const showError = mutation.isError && !settled(mutation.error);

  return (
    <form
      noValidate
      onSubmit={(e) => {
        e.preventDefault();
        if (reason && !tooLong && !gate) mutation.mutate();
      }}
      className="flex flex-col gap-4"
    >
      <fieldset aria-labelledby={legendId} className="flex flex-col gap-1">
        <p id={legendId} className="text-fg/90 mb-1 text-sm font-medium">
          Pourquoi signales-tu ce contenu ?
        </p>
        {REPORT_REASONS.map((r) => (
          <label
            key={r.value}
            className="hover:bg-surface-2 has-[:checked]:bg-surface-2 flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-sm"
          >
            <input
              type="radio"
              name="reason"
              value={r.value}
              checked={reason === r.value}
              onChange={() => setReason(r.value)}
              className="accent-accent size-4"
            />
            {r.label}
          </label>
        ))}
      </fieldset>
      <TextArea
        label="Précisions (facultatif)"
        value={details}
        onChange={(e) => setDetails(e.target.value)}
        maxLength={DETAILS_MAX}
        error={tooLong ? `${DETAILS_MAX} caractères maximum.` : null}
        hint={`${details.length} / ${DETAILS_MAX}`}
      />
      {gate && <Alert tone="warning">{gate}</Alert>}
      {showError && <Alert tone="error">{inlineError(mutation.error)}</Alert>}
      <Button type="submit" disabled={!reason || tooLong || !!gate} loading={mutation.isPending}>
        Envoyer le signalement
      </Button>
    </form>
  );
}
