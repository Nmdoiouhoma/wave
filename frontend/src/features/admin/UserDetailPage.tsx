// W-73: user detail with suspend / unsuspend and role change (note required, confirmation dialog).
// Buttons are hidden when the role guard (permissions.ts, ADR 013 §2) forbids them; the API stays the authority.
import { useCallback, useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router';
import { useCurrentUser } from '@/api/queries/me';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { SelectField, TextArea } from '@/components/ui/Field';
import { Avatar } from '@/components/ui/Media';
import { Sheet } from '@/components/ui/Sheet';
import { Spinner } from '@/components/ui/Spinner';
import { errorMessage } from '@/lib/errorMessages';
import { formatDate } from '@/lib/format';
import { toast } from '@/stores/toast';
import { AdminError } from './AdminError';
import type { AdminUserDetail } from './adminApi';
import { UserBadges } from './badges';
import { canSetRole, canSuspend, isAdmin, ROLE_LABELS } from './permissions';
import { useAdminUser, useSetUserRole, useSuspendUser, useUnsuspendUser } from './queries';

const NOTE_MAX = 500;

const dateTime = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeStyle: 'short' });
const when = (iso: string | null) => (iso ? dateTime.format(new Date(iso)) : 'Jamais');

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 py-2.5 sm:flex-row sm:items-baseline sm:gap-4">
      <dt className="text-muted w-48 shrink-0 text-sm">{label}</dt>
      <dd className="min-w-0 text-sm break-words">{children}</dd>
    </div>
  );
}

type Action = 'suspend' | 'unsuspend' | 'role';

/** Confirmation dialog: a note of 1..500 characters is required before any action. */
function ActionDialog({ user, action, onClose }: { user: AdminUserDetail; action: Action; onClose: () => void }) {
  const [note, setNote] = useState('');
  const [role, setRole] = useState<'ROLE_USER' | 'ROLE_MODERATOR'>(
    user.role === 'ROLE_MODERATOR' ? 'ROLE_USER' : 'ROLE_MODERATOR',
  );
  const suspend = useSuspendUser(user.id);
  const unsuspend = useUnsuspendUser(user.id);
  const setRoleM = useSetUserRole(user.id);
  const m = action === 'suspend' ? suspend : action === 'unsuspend' ? unsuspend : setRoleM;
  const trimmed = note.trim();
  const valid = trimmed.length >= 1 && trimmed.length <= NOTE_MAX;
  const name = `@${user.username}`;

  const title = {
    suspend: `Suspendre ${name}`,
    unsuspend: `Réactiver ${name}`,
    role: `Changer le rôle de ${name}`,
  }[action];
  const done = {
    suspend: 'Compte suspendu.',
    unsuspend: 'Compte réactivé.',
    role: 'Rôle mis à jour.',
  }[action];

  const confirm = () => {
    if (!valid || m.isPending) return;
    const onSuccess = () => {
      toast(done, 'success');
      onClose();
    };
    if (action === 'suspend') suspend.mutate(trimmed, { onSuccess });
    else if (action === 'unsuspend') unsuspend.mutate(trimmed, { onSuccess });
    else setRoleM.mutate({ role, note: trimmed }, { onSuccess });
  };

  return (
    <Sheet open title={title} onClose={onClose}>
      <form
        className="flex flex-col gap-4"
        onSubmit={(e) => {
          e.preventDefault();
          confirm();
        }}
      >
        <p className="text-muted text-sm">
          {action === 'suspend' &&
            'Le compte est déconnecté partout et ne peut plus se connecter tant qu’il est suspendu.'}
          {action === 'unsuspend' && 'La personne pourra se reconnecter (aucune session n’est restaurée).'}
          {action === 'role' && 'Le nouveau rôle s’applique à sa prochaine requête.'}
        </p>
        {action === 'role' && (
          <SelectField label="Nouveau rôle" value={role} onChange={(e) => setRole(e.target.value as typeof role)}>
            <option value="ROLE_USER">{ROLE_LABELS.ROLE_USER}</option>
            <option value="ROLE_MODERATOR">{ROLE_LABELS.ROLE_MODERATOR}</option>
          </SelectField>
        )}
        <TextArea
          label="Motif (obligatoire, inscrit au journal)"
          value={note}
          onChange={(e) => setNote(e.target.value)}
          maxLength={NOTE_MAX}
          required
          data-autofocus
          hint={`${trimmed.length}/${NOTE_MAX}`}
        />
        {m.isError && <Alert tone="error">{errorMessage(m.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" variant={action === 'suspend' ? 'danger' : 'primary'} disabled={!valid} loading={m.isPending}>
            Confirmer
          </Button>
        </div>
      </form>
    </Sheet>
  );
}

export function UserDetailPage() {
  const { userId = '' } = useParams();
  const me = useCurrentUser();
  const q = useAdminUser(userId);
  const [action, setAction] = useState<Action | null>(null);
  const closeDialog = useCallback(() => setAction(null), []);

  if (q.isPending) return <Spinner label="Chargement du compte" className="mx-auto mt-16" />;
  if (q.isError) return <AdminError error={q.error} onRetry={() => void q.refetch()} />;
  const u = q.data;
  const suspendable = canSuspend(me.roles, me.id, u);
  const roleChangeable = canSetRole(me.roles, me.id, u);
  const c = u.counts;
  const counts: [string, number][] = [
    ['Morceaux', c.tracks],
    ['Posts', c.posts],
    ['Commentaires', c.comments],
    ['Playlists', c.playlists],
    ['Artistes', c.artists],
    ['Abonnés', c.followers],
    ['Abonnements', c.following],
    ['Signalements faits', c.reportsFiled],
  ];

  return (
    <div className="flex flex-col gap-4">
      <Link to="/admin/users" className="text-muted hover:text-fg w-fit text-sm">
        ← Utilisateurs
      </Link>

      <header className="flex items-center gap-4">
        <Avatar url={u.avatarUrl} name={u.displayName} seed={u.id} className="size-16 text-xl" />
        <div className="min-w-0 flex-1">
          <h1 className="truncate text-2xl font-bold tracking-tight">{u.displayName}</h1>
          <p className="text-muted truncate text-sm">@{u.username}</p>
          <div className="mt-1.5 flex flex-wrap gap-1.5">
            <UserBadges user={u} />
          </div>
        </div>
      </header>

      {(suspendable || roleChangeable) && (
        <div className="flex flex-wrap gap-2">
          {suspendable &&
            (u.suspendedAt ? (
              <Button variant="secondary" onClick={() => setAction('unsuspend')}>
                Réactiver le compte
              </Button>
            ) : (
              <Button variant="danger" onClick={() => setAction('suspend')}>
                Suspendre
              </Button>
            ))}
          {roleChangeable && (
            <Button variant="secondary" onClick={() => setAction('role')}>
              Changer le rôle
            </Button>
          )}
        </div>
      )}
      {u.id === me.id && <Alert tone="info">C’est ton compte : aucune action n’est possible sur toi-même.</Alert>}

      <section className="bg-surface border-line rounded-[20px] border px-5 py-2" aria-label="Compte">
        <dl className="divide-line divide-y">
          {u.email !== null && (
            <Row label="E-mail">
              {u.email} {u.emailVerified ? '(vérifié)' : '(non vérifié)'}
            </Row>
          )}
          <Row label="Rôle">{ROLE_LABELS[u.role]}</Row>
          <Row label="Inscrit le">{formatDate(u.createdAt)}</Row>
          <Row label="Dernière connexion">{when(u.lastLoginAt)}</Row>
          {isAdmin(me.roles) && (
            <Row label="Journal">
              <Link to={`/admin/users/${u.id}/activity`} className="text-accent hover:underline">
                Voir l’activité du compte
              </Link>
            </Row>
          )}
          <Row label="Suspension">
            {u.suspendedAt ? `Suspendu depuis le ${when(u.suspendedAt)}` : 'Non suspendu'}
          </Row>
          <Row label="Dernière suspension">
            {u.lastSuspension ? (
              <>
                {when(u.lastSuspension.at)}
                {u.lastSuspension.by ? ` par @${u.lastSuspension.by.username}` : ''}
                {u.lastSuspension.note ? <span className="text-muted block">« {u.lastSuspension.note} »</span> : null}
              </>
            ) : (
              'Aucune'
            )}
          </Row>
          <Row label="Signalements ouverts">{u.openReportCount}</Row>
          {u.openAlertCount !== null && <Row label="Alertes ouvertes">{u.openAlertCount}</Row>}
        </dl>
      </section>

      <section className="bg-surface border-line rounded-[20px] border p-5" aria-label="Activité du compte">
        <dl className="grid grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-4">
          {counts.map(([label, n]) => (
            <div key={label}>
              <dd className="text-xl font-bold tabular-nums">{n.toLocaleString('fr-FR')}</dd>
              <dt className="text-muted text-xs">{label}</dt>
            </div>
          ))}
        </dl>
      </section>

      {action && <ActionDialog key={action} user={u} action={action} onClose={closeDialog} />}
    </div>
  );
}
