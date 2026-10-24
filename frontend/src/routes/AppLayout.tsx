import { useCallback, useState } from 'react';
import { Link, NavLink, Outlet } from 'react-router';
import { useCurrentUser } from '@/api/queries/me';
import { cx } from '@/components/ui/cx';
import { HomeIcon, MicIcon, UploadIcon, UserIcon, WaveLogo } from '@/components/ui/icons';
import { Avatar } from '@/components/ui/Media';
import { VerifyEmailBanner } from '@/features/auth/VerifyEmailBanner';
import { FullPlayer } from '@/features/player/FullPlayer';
import { MiniPlayer } from '@/features/player/MiniPlayer';
import { usePlayer } from '@/stores/player';
import { MOCKS_ENABLED } from '@/lib/config';

function useNavItems() {
  const me = useCurrentUser();
  return [
    { to: '/', label: 'Accueil', icon: HomeIcon, end: true },
    { to: '/upload', label: 'Importer', icon: UploadIcon },
    { to: '/me/artists', label: 'Artistes', icon: MicIcon },
    { to: `/u/${me.username}`, label: 'Profil', icon: UserIcon },
  ];
}

function Logo() {
  return (
    <Link to="/" className="flex items-center gap-2" aria-label="Wave, accueil">
      <span className="bg-wave flex size-8 items-center justify-center rounded-lg text-white">
        <WaveLogo size={18} />
      </span>
      <span className="text-lg font-bold tracking-tight">Wave</span>
    </Link>
  );
}

function Sidebar() {
  const items = useNavItems();
  const me = useCurrentUser();
  return (
    <aside className="border-line bg-surface/50 fixed inset-y-0 left-0 hidden w-60 flex-col gap-8 border-r px-4 py-6 md:flex">
      <div className="px-2">
        <Logo />
      </div>
      <nav aria-label="Navigation principale" className="flex flex-col gap-1">
        {items.map(({ to, label, icon: Icon, end }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            className={({ isActive }) =>
              cx(
                'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition',
                isActive ? 'bg-surface-2 text-fg' : 'text-muted hover:text-fg',
              )
            }
          >
            <Icon size={20} />
            {label}
          </NavLink>
        ))}
      </nav>
      <div className="mt-auto flex flex-col gap-2">
        {MOCKS_ENABLED && (
          <Link to="/dev/mailbox" className="text-faint hover:text-fg rounded-xl px-3 py-2 text-xs">
            Boîte mail de démo
          </Link>
        )}
        <Link
          to="/settings"
          className="hover:bg-surface-2 flex items-center gap-3 rounded-xl px-2 py-2"
        >
          <Avatar
            url={me.avatarUrl}
            name={me.displayName}
            seed={me.id}
            className="size-9 text-xs"
          />
          <span className="min-w-0">
            <span className="block truncate text-sm font-semibold">{me.displayName}</span>
            <span className="text-muted block truncate text-xs">Paramètres</span>
          </span>
        </Link>
      </div>
    </aside>
  );
}

function TopBar() {
  const me = useCurrentUser();
  return (
    <header className="border-line/60 bg-bg/85 sticky top-0 z-30 flex h-14 items-center justify-between border-b px-4 backdrop-blur md:hidden">
      <Logo />
      <div className="flex items-center gap-2">
        {MOCKS_ENABLED && (
          <Link to="/dev/mailbox" className="text-faint text-xs">
            Mails démo
          </Link>
        )}
        <Link to="/settings" aria-label="Paramètres">
          <Avatar
            url={me.avatarUrl}
            name={me.displayName}
            seed={me.id}
            className="size-8 text-xs"
          />
        </Link>
      </div>
    </header>
  );
}

function BottomNav() {
  const items = useNavItems();
  return (
    <nav
      aria-label="Navigation principale"
      className="pb-safe border-line bg-bg/95 grid grid-cols-4 border-t backdrop-blur md:hidden"
    >
      {items.map(({ to, label, icon: Icon, end }) => (
        <NavLink
          key={to}
          to={to}
          end={end}
          className={({ isActive }) =>
            cx(
              'flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium',
              isActive ? 'text-fg' : 'text-faint',
            )
          }
        >
          <Icon size={22} />
          {label}
        </NavLink>
      ))}
    </nav>
  );
}

export function AppLayout() {
  const hasTrack = usePlayer((s) => s.index >= 0);
  const [fullOpen, setFullOpen] = useState(false);
  const close = useCallback(() => setFullOpen(false), []);

  return (
    <div className="min-h-dvh md:pl-60">
      <Sidebar />
      <TopBar />
      <VerifyEmailBanner />
      <main
        id="main"
        className={cx('px-4 pt-4 md:px-8 md:pt-8', hasTrack ? 'pb-44 md:pb-28' : 'pb-24 md:pb-10')}
      >
        <Outlet />
      </main>
      <div className="fixed inset-x-0 bottom-0 z-40 md:left-60">
        <MiniPlayer onExpand={() => setFullOpen(true)} />
        <BottomNav />
      </div>
      <FullPlayer open={fullOpen} onClose={close} />
    </div>
  );
}
