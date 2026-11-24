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
  ShieldIcon,
  SidebarIcon,
  UserIcon,
} from '@/components/ui/icons';
import { ThemeToggle } from '@/components/ui/ThemeToggle';
import { Toaster } from '@/components/ui/Toaster';
import { Avatar } from '@/components/ui/Media';
import { isStaff } from '@/features/admin/permissions';
import { TrackSheets } from '@/features/playlists/TrackSheets';
import { LogoutButton } from '@/features/auth/LogoutButton';
import { AnnouncementBanner } from '@/features/admin/announcements/AnnouncementBanner';
import { VerifyEmailBanner } from '@/features/auth/VerifyEmailBanner';
import { BellIcon, NotificationBell, UnreadBadge } from '@/features/notifications/NotificationBell';
import { useBellLabel, useUnreadCount } from '@/features/notifications/queries';
import { FullPlayer } from '@/features/player/FullPlayer';
import { MiniPlayer } from '@/features/player/MiniPlayer';
import { usePlayer } from '@/stores/player';
import { useRailCollapsed } from '@/stores/rail';
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
  /** Desktop rail folded to icons only (fb-1124). */
  collapsed: boolean;
  onToggleCollapsed: () => void;
}

function Logo({ short = false }: { short?: boolean }) {
  return (
    <Link to="/" className="flex items-center gap-2" aria-label="Wave, Pour toi">
      <span className="text-[26px] leading-none font-bold tracking-[-0.04em]">
        {short ? 'w' : 'wave'}
        <span className="text-accent">.</span>
      </span>
    </Link>
  );
}

function Rail({
  active,
  notificationsOn,
  createOpen,
  onCreate,
  createRef,
  collapsed,
  onToggleCollapsed,
}: NavProps) {
  const entries = useEntries();
  const bellLabel = useBellLabel();
  const me = useCurrentUser();
  const item = (on: boolean) =>
    cx(
      'flex min-h-11 items-center gap-3 rounded-full py-2.5 text-sm font-medium transition',
      collapsed ? 'justify-center px-0' : 'px-3',
      on ? 'bg-fg text-bg' : 'text-muted hover:text-fg',
    );
  // Folded: labels leave the layout but stay as the accessible name and the tooltip.
  const label = (text: string) => (collapsed ? <span className="sr-only">{text}</span> : text);
  const toggleLabel = collapsed ? 'Déplier la barre latérale' : 'Replier la barre latérale';
  return (
    <aside
      className={cx(
        'border-line bg-bg fixed inset-y-0 left-0 flex flex-col gap-8 border-r py-6 transition-[width]',
        collapsed ? 'w-[72px] px-3' : 'w-60 px-4',
      )}
    >
      <div
        className={cx('flex items-center', collapsed ? 'flex-col gap-3' : 'justify-between px-2')}
      >
        <Logo short={collapsed} />
        <button
          type="button"
          onClick={onToggleCollapsed}
          aria-label={toggleLabel}
          title={toggleLabel}
          aria-expanded={!collapsed}
          className="text-muted hover:text-fg hover:bg-surface-2 flex size-9 items-center justify-center rounded-full transition"
        >
          <SidebarIcon size={18} />
        </button>
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
              title={collapsed ? e.label : undefined}
              className={item(on)}
            >
              <Icon size={20} />
              {label(e.label)}
            </button>
          ) : (
            <Link
              key={e.id}
              to={e.to}
              aria-current={on ? 'page' : undefined}
              title={collapsed ? e.label : undefined}
              className={item(on)}
            >
              <Icon size={20} />
              {label(e.label)}
            </Link>
          );
        })}
      </nav>
      {/* W-46: not a tab (no tab is active on /notifications): its own entry, outside the main nav. */}
      <Link
        to="/notifications"
        aria-label={bellLabel}
        aria-current={notificationsOn ? 'page' : undefined}
        title={collapsed ? 'Notifications' : undefined}
        className={cx(item(notificationsOn), collapsed && 'relative')}
      >
        <BellIcon size={20} />
        {collapsed ? (
          <span className="absolute top-1 right-1">
            <UnreadBadge />
          </span>
        ) : (
          <>
            <span className="flex-1">Notifications</span>
            <UnreadBadge />
          </>
        )}
      </Link>
      {MOCKS_ENABLED && !collapsed && (
        <Link to="/dev/mailbox" className="text-faint hover:text-fg rounded-xl px-3 py-2 text-xs">
          Boîte mail de démo
        </Link>
      )}
      {/* FB-25: account block, logout one click away. */}
      <div
        className={cx(
          'border-line bg-surface mt-auto flex flex-col gap-2 rounded-2xl border',
          collapsed ? 'items-center p-1.5' : 'p-2.5',
        )}
      >
        <Link
          to="/settings"
          aria-label="Paramètres"
          title={collapsed ? 'Paramètres' : undefined}
          className={item(false)}
        >
          <Avatar
            url={me.avatarUrl}
            name={me.displayName}
            seed={me.id}
            className="size-8 text-xs"
          />
          {!collapsed && <span className="truncate">{me.displayName}</span>}
        </Link>
        {isStaff(me.roles) && (
          <Link
            to="/admin"
            title={collapsed ? 'Administration' : undefined}
            className={item(false)}
          >
            <ShieldIcon size={20} />
            {label('Administration')}
          </Link>
        )}
        {/* Folded: the theme stays reachable in Paramètres. */}
        {!collapsed && <ThemeToggle />}
        <LogoutButton iconOnly={collapsed} />
      </div>
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
                  <span className="bg-accent text-accent-contrast flex h-7 w-10 items-center justify-center rounded-full">
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
  const [collapsed, setCollapsed] = useRailCollapsed();
  const folded = desktop && collapsed;

  const nav: NavProps = {
    active,
    collapsed,
    onToggleCollapsed: () => setCollapsed(!collapsed),
    notificationsOn,
    createOpen,
    createRef,
    onCreate: () => {
      setFullOn(null);
      setCreateOn(createOpen ? null : pathname);
    },
  };

  return (
    <div className={cx('min-h-dvh', folded ? 'md:pl-[72px]' : 'md:pl-60')}>
      {desktop && <Rail {...nav} />}
      {/* The full player is not modal (the tabs stay usable): what it covers leaves the tab order. */}
      <div inert={fullOpen}>
        {!desktop && !feed && <TopBar bell={active === 'profile'} />}
        <VerifyEmailBanner />
        <AnnouncementBanner />
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
      <div
        className={cx('fixed inset-x-0 bottom-0 z-40', folded ? 'md:left-[72px]' : 'md:left-60')}
      >
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
