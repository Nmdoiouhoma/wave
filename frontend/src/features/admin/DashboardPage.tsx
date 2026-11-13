import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { Spinner } from '@/components/ui/Spinner';
import { AdminError } from './AdminError';
import { useAdminDashboard } from './queries';

function Card({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="bg-surface border-line rounded-[20px] border p-5" aria-label={title}>
      <h2 className="text-muted mb-3 text-xs font-semibold tracking-wide uppercase">{title}</h2>
      <dl className="grid grid-cols-2 gap-x-4 gap-y-3">{children}</dl>
    </section>
  );
}

function Stat({ label, value, to }: { label: string; value: number; to?: string }) {
  const inner = (
    <>
      <dd className="text-2xl font-bold tabular-nums">{value.toLocaleString('fr-FR')}</dd>
      <dt className="text-muted text-xs">{label}</dt>
    </>
  );
  return to ? (
    <Link to={to} className="hover:bg-surface-2 -m-1.5 flex flex-col rounded-xl p-1.5">
      {inner}
    </Link>
  ) : (
    <div className="flex flex-col">{inner}</div>
  );
}

export function DashboardPage() {
  const q = useAdminDashboard();
  if (q.isPending) return <Spinner label="Chargement du tableau de bord" className="mx-auto mt-16" />;
  if (q.isError) return <AdminError error={q.error} onRetry={() => void q.refetch()} />;
  const d = q.data;
  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-2xl font-bold tracking-tight">Tableau de bord</h1>
      <div className="grid gap-4 md:grid-cols-2">
        <Card title="Utilisateurs">
          <Stat label="Comptes" value={d.users.total} to="/admin/users" />
          <Stat label="Nouveaux (24 h)" value={d.users.new24h} />
          <Stat label="Nouveaux (7 j)" value={d.users.new7d} />
          <Stat label="Suspendus" value={d.users.suspended} to="/admin/users?status=suspended" />
          <Stat label="E-mail non vérifié" value={d.users.unverified} to="/admin/users?status=unverified" />
          <Stat label="Modérateurs" value={d.users.moderators} to="/admin/users?role=ROLE_MODERATOR" />
          <Stat label="Administrateurs" value={d.users.admins} to="/admin/users?role=ROLE_ADMIN" />
        </Card>
        <Card title="Modération">
          <Stat label="Cibles signalées" value={d.moderation.openReportTargets} to="/admin/reports" />
          <Stat label="Signalements ouverts" value={d.moderation.openReports} to="/admin/reports" />
          <Stat label="Masqués (24 h)" value={d.moderation.hiddenContent24h} />
        </Card>
        <Card title="Activité (24 h)">
          <Stat label="Morceaux" value={d.content.tracks24h} />
          <Stat label="Posts" value={d.content.posts24h} />
          <Stat label="Commentaires" value={d.content.comments24h} />
          <Stat label="Envois refusés" value={d.content.failedUploads24h} />
        </Card>
        {d.alerts && (
          <Card title="Alertes">
            <Stat label="Ouvertes" value={d.alerts.open} />
            <Stat label="Gravité haute" value={d.alerts.openHigh} />
            <Stat label="Gravité moyenne" value={d.alerts.openMedium} />
            <Stat label="Gravité basse" value={d.alerts.openLow} />
          </Card>
        )}
        {d.announcements && (
          <Card title="Annonces">
            <Stat label="Actives" value={d.announcements.active} />
            <Stat label="Programmées" value={d.announcements.scheduled} />
          </Card>
        )}
      </div>
      <p className="text-muted text-xs">
        Chiffres mis à jour toutes les minutes au plus.
      </p>
    </div>
  );
}
