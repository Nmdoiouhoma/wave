// W-38 (FB-11): navigation shell. Mobile first: bottom tab bar under 768 px, left rail above.
import { useCallback, useRef, useState, type ComponentType } from 'react';
import { Link, Outlet, useLocation } from 'react-router';
import { useCurrentUser } from '@/api/queries/me';
import { cx } from '@/components/ui/cx';
import {
  CompassIcon,
  HomeIcon,
  LibraryIcon,
  PlusIcon,
  UserIcon,
  WaveLogo,
} from '@/components/ui/icons';
import { Toaster } from '@/components/ui/Toaster';
import { Avatar } from '@/components/ui/Media';
import { TrackSheets } from '@/features/playlists/TrackSheets';
import { VerifyEmailBanner } from '@/features/auth/VerifyEmailBanner';
import { BellIcon, NotificationBell, UnreadBadge } from '@/features/notifications/NotificationBell';
import { useBellLabel, useUnreadCount } from '@/features/notifications/queries';
import { FullPlayer } from '@/features/player/FullPlayer';
import { MiniPlayer } from '@/features/player/MiniPlayer';
import { usePlayer } from '@/stores/player';
import { MOCKS_ENABLED } from '@/lib/config';
import { DESKTOP_QUERY, useMediaQuery } from '@/lib/useMediaQuery';
import { CreateMenu } from './CreateMenu';
import { activeTab, isFeedPath, type TabId } from './navigation';

type Entry =
  | {
      id: Exclude<TabId, 'create'>;
      kind: 'link';
      to: string;
      label: string;
      icon: ComponentType<{ size?: number }>;
    }
  | { id: 'create'; kind: 'create'; label: string; icon: ComponentType<{ size?: number }> };

function useEntries(): Entry[] {
  const me = useCurrentUser();
  return [
    { id: 'for-you', kind: 'link', to: '/', label: 'Pour toi', icon: HomeIcon },
    { id: 'discover', kind: 'link', to: '/discover', label: 'Découvrir', icon: CompassIcon },
    { id: 'create', kind: 'create', label: 'Créer', icon: PlusIcon },
    { id: 'library', kind: 'link', to: '/library', label: 'Bibliothèque', icon: LibraryIcon },
    {
      id: 'profile',
      kind: 'link',
      to: `/u/${encodeURIComponent(me.username)}`,
      label: 'Profil',
      icon: UserIcon,
    },
  ];
}

interface NavProps {
  active: TabId | null;
  notificationsOn: boolean;
  createOpen: boolean;
  onCreate: () => void;
  createRef: React.RefObject<HTMLButtonElement | null>;
}

function Logo() {
  return (
    <Link to="/" className="flex items-center gap-2" aria-label="Wave, Pour toi">
      <span className="bg-wave flex size-8 items-center justify-center rounded-lg text-white">
        <WaveLogo size={18} />
      </span>
      <span className="text-lg font-bold tracking-tight">Wave</span>
    </Link>
  );
}

function Rail({ active, notificationsOn, createOpen, onCreate, createRef }: NavProps) {
  const entries = useEntries();
  const bellLabel = useBellLabel();
  const item = (on: boolean) =>
    cx(
      'flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition',
      on ? 'bg-surface-2 text-fg' : 'text-muted hover:text-fg',
    );
  return (
    <aside className="border-line bg-surface/50 fixed inset-y-0 left-0 flex w-60 flex-col gap-8 border-r px-4 py-6">
      <div className="px-2">
        <Logo />
      </div>
      <nav aria-label="Navigation principale" className="flex flex-col gap-1">
        {entries.map((e) => {
          const Icon = e.icon;
          const on = active === e.id;
          return e.kind === 'create' ? (
            <button
              key={e.id}
              ref={createRef}
              type="button"
              onClick={onCreate}
              aria-haspopup="dialog"
              aria-expanded={createOpen}
              aria-current={on ? 'page' : undefined}
              className={item(on)}
            >
              <Icon size={20} />
              {e.label}
            </button>
          ) : (
            <Link key={e.id} to={e.to} aria-current={on ? 'page' : undefined} className={item(on)}>
              <Icon size={20} />
              {e.label}
            </Link>
          );
        })}
      </nav>
      {/* W-46: not a tab (no tab is active on /notifications): its own entry, outside the main nav. */}
      <Link
        to="/notifications"
        aria-label={bellLabel}
        aria-current={notificationsOn ? 'page' : undefined}
        className={item(notificationsOn)}
      >
        <BellIcon size={20} />
        <span className="flex-1">Notifications</span>
        <UnreadBadge />
      </Link>
      {MOCKS_ENABLED && (
        <Link
          to="/dev/mailbox"
          className="text-faint hover:text-fg mt-auto rounded-xl px-3 py-2 text-xs"
        >
          Boîte mail de démo
        </Link>
      )}
    </aside>
  );
}

function TabBar({ active, createOpen, onCreate, createRef }: NavProps) {
  const entries = useEntries();
  return (
    <nav
      aria-label="Navigation principale"
      className="pb-safe border-line bg-bg/95 border-t backdrop-blur"
    >
      <ul className="grid h-14 grid-cols-5">
        {entries.map((e) => {
          const Icon = e.icon;
          const on = active === e.id;
          const cls = cx(
            'flex h-full min-h-11 w-full flex-col items-center justify-center gap-0.5 text-[11px] font-medium',
            on ? 'text-fg' : 'text-faint',
          );
          return (
            <li key={e.id}>
              {e.kind === 'create' ? (
                <button
                  ref={createRef}
                  type="button"
                  onClick={onCreate}
                  aria-haspopup="dialog"
                  aria-expanded={createOpen}
                  aria-current={on ? 'page' : undefined}
                  className={cls}
                >
                  <span className="bg-wave flex h-7 w-10 items-center justify-center rounded-lg text-white">
                    <Icon size={18} />
                  </span>
                  {e.label}
                </button>
              ) : (
                <Link to={e.to} aria-current={on ? 'page' : undefined} className={cls}>
                  <Icon size={22} />
                  {e.label}
                </Link>
              )}
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

function TopBar({ bell }: { bell: boolean }) {
  const me = useCurrentUser();
  return (
    <header className="border-line/60 bg-bg/85 sticky top-0 z-30 flex h-14 items-center justify-between border-b px-4 backdrop-blur">
      <Logo />
      <div className="flex items-center gap-2">
        {MOCKS_ENABLED && (
          <Link to="/dev/mailbox" className="text-faint text-xs">
            Mails démo
          </Link>
        )}
        {bell && <NotificationBell />}
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

export function AppLayout() {
  const me = useCurrentUser();
  const { pathname } = useLocation();
  const desktop = useMediaQuery(DESKTOP_QUERY);
  const feed = isFeedPath(pathname);
  const active = activeTab(pathname, me.username);
  const hasTrack = usePlayer((s) => s.index >= 0);
  const showMini = hasTrack && !feed;
  const notificationsOn = pathname === '/notifications';
  // W-46: the single unread-count poller of the app (the bells only read the cache).
  useUnreadCount({ poll: true });

  // Each overlay remembers the path it was opened on: any navigation (a tab, a link in the
  // player) closes the full player and the + menu without an effect.
  const [fullOn, setFullOn] = useState<string | null>(null);
  const [createOn, setCreateOn] = useState<string | null>(null);
  const fullOpen = fullOn === pathname;
  const createOpen = createOn === pathname;
  const closeFull = useCallback(() => setFullOn(null), []);
  const closeCreate = useCallback(() => setCreateOn(null), []);
  const createRef = useRef<HTMLButtonElement>(null);

  const nav: NavProps = {
    active,
    notificationsOn,
    createOpen,
    createRef,
    onCreate: () => {
      setFullOn(null);
      setCreateOn(createOpen ? null : pathname);
    },
  };

  return (
    <div className="min-h-dvh md:pl-60">
      {desktop && <Rail {...nav} />}
      {/* The full player is not modal (the tabs stay usable): what it covers leaves the tab order. */}
      <div inert={fullOpen}>
        {!desktop && !feed && <TopBar bell={active === 'profile'} />}
        <VerifyEmailBanner />
      </div>
      <main
        inert={fullOpen}
        id="main"
        className={cx(
          feed
            ? // The feed fills the viewport minus the tab bar (W-34 owns this screen).
              'h-[calc(100dvh-3.5rem-env(safe-area-inset-bottom))] overflow-hidden md:h-dvh'
            : cx(
                'px-4 pt-4 md:px-8 md:pt-8',
                showMini
                  ? 'pb-[calc(11rem+env(safe-area-inset-bottom))] md:pb-28'
                  : 'pb-[calc(6rem+env(safe-area-inset-bottom))] md:pb-10',
              ),
        )}
      >
        <Outlet />
      </main>
      <div className="fixed inset-x-0 bottom-0 z-40 md:left-60">
        {showMini && (
          <div inert={fullOpen}>
            <MiniPlayer onExpand={() => setFullOn(pathname)} />
          </div>
        )}
        {!desktop && <TabBar {...nav} />}
      </div>
      <FullPlayer open={fullOpen} onClose={closeFull} />
      <CreateMenu
        open={createOpen}
        variant={desktop ? 'popover' : 'sheet'}
        onClose={closeCreate}
        trigger={createRef}
      />
      <TrackSheets />
      <Toaster />
    </div>
  );
}
