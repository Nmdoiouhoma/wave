// Confirmation sheets: decision (note optional) and irreversible deletion (note + typed word).
import { useState } from 'react';
import { Sheet } from '@/components/ui/Sheet';
import { toast } from '@/stores/toast';
import type { AdminContentType, DecisionAction, ReportTargetType } from './moderationApi';
import { DELETE_WORD } from './helpers';
import { DialogForm } from './DialogForm';
import { ACTION_COPY, moderationError } from './moderationLabels';
import { useDecide, useDeleteContent } from './useModeration';

export interface DialogTarget {
  type: ReportTargetType;
  id: string;
  label: string;
}

/** `ask(target, action)` opens the confirmation; `node` must be rendered once by the page. */
export function useDecisionDialog(onDone?: () => void) {
  const [state, setState] = useState<{ target: DialogTarget; action: DecisionAction } | null>(null);
  const decide = useDecide();
  const close = () => {
    setState(null);
    decide.reset();
  };
  const copy = state && ACTION_COPY[state.action];
  const node = (
    <Sheet open={!!state} title={copy?.title ?? ''} onClose={close}>
      {state && copy && (
        <DialogForm
          key={`${state.target.type}:${state.target.id}:${state.action}`}
          description={
            <>
              <strong className="text-fg">{state.target.label}</strong>. {copy.body}
            </>
          }
          confirm={copy.confirm}
          danger={copy.danger}
          noteLabel="Note (facultative)"
          noteRequired={false}
          pending={decide.isPending}
          error={decide.isError ? moderationError(decide.error) : null}
          onCancel={close}
          onSubmit={(note) =>
            decide.mutate(
              {
                type: state.target.type,
                id: state.target.id,
                action: state.action,
                note: note || null,
              },
              {
                onSuccess: () => {
                  toast(copy.done, 'success');
                  close();
                  onDone?.();
                },
              },
            )
          }
        />
      )}
    </Sheet>
  );
  return {
    ask: (target: DialogTarget, action: DecisionAction) => setState({ target, action }),
    node,
  };
}

export function useDeleteDialog() {
  const [target, setTarget] = useState<(DialogTarget & { type: AdminContentType }) | null>(null);
  const del = useDeleteContent();
  const close = () => {
    setTarget(null);
    del.reset();
  };
  const node = (
    <Sheet open={!!target} title="Supprimer définitivement" onClose={close}>
      {target && (
        <DialogForm
          key={`${target.type}:${target.id}`}
          description={
            <>
              <strong className="text-fg">{target.label}</strong>. Suppression irréversible, avec
              les signalements liés. Le propriétaire n’est pas prévenu.
            </>
          }
          confirm="Supprimer"
          danger
          noteLabel="Motif (obligatoire)"
          noteRequired
          typed={DELETE_WORD}
          pending={del.isPending}
          error={del.isError ? moderationError(del.error) : null}
          onCancel={close}
          onSubmit={(note) =>
            del.mutate(
              { type: target.type, id: target.id, note },
              {
                onSuccess: () => {
                  toast('Contenu supprimé', 'success');
                  close();
                },
              },
            )
          }
        />
      )}
    </Sheet>
  );
  return { ask: (t: DialogTarget & { type: AdminContentType }) => setTarget(t), node };
}
