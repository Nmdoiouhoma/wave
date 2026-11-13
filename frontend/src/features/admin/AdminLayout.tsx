// W-73: back-office shell. Route guard on `me.roles`, menu by role; pages render in the <Outlet />.
// Desktop: left menu. Phone: top bar + scrollable tabs. No player, no feed chrome.
import { Link, NavLink, Navigate, Outlet } from 'react-router';
import { useCurrentUser } from '@/api/queries/me';
import { cx } from '@/components/ui/cx';
import { ADMIN_NAV } from './adminNav';
import { isAdmin, isStaff } from './permissions';

export function AdminLayout() {
  const me = useCurrentUser();
  if (!isStaff(me.roles)) return <Navigate to="/" replace />;
  const entries = ADMIN_NAV.filter((e) => !e.adminOnly || isAdmin(me.roles));
  const link = ({ isActive }: { isActive: boolean }) =>
    cx(
      'flex min-h-11 shrink-0 items-center rounded-full px-4 py-2 text-sm font-medium whitespace-nowrap transition',
      isActive ? 'bg-fg text-bg' : 'text-muted hover:text-fg',
    );
  return (
    <div className="min-h-dvh md:pl-60">
      <aside className="border-line bg-bg md:fixed md:inset-y-0 md:left-0 md:flex md:w-60 md:flex-col md:gap-6 md:border-r md:px-4 md:py-6">
        <div className="flex items-center justify-between gap-3 px-4 pt-3 md:flex-col md:items-start md:gap-1 md:px-2 md:pt-0">
          <span className="text-[22px] leading-none font-bold tracking-[-0.04em]">
            wave<span className="text-accent">.</span>
            <span className="text-muted ml-2 text-sm font-medium tracking-normal">admin</span>
          </span>
          <Link to="/" className="text-muted hover:text-fg text-sm underline-offset-2 hover:underline">
            Retour à Wave
          </Link>
        </div>
        <nav
          aria-label="Administration"
          className="flex gap-1 overflow-x-auto px-3 py-2 md:flex-col md:overflow-visible md:px-0 md:py-0"
        >
          {entries.map((e) => (
            <NavLink key={e.to} to={e.to} end={e.end} className={link}>
              {e.label}
            </NavLink>
          ))}
        </nav>
      </aside>
      <main id="main" className="mx-auto w-full max-w-5xl px-4 pt-4 pb-16 md:px-8 md:pt-8">
        <Outlet />
      </main>
    </div>
  );
}
