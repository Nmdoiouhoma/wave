import { createBrowserRouter, type RouteObject } from 'react-router';
import { ArtistPage } from '@/features/artists/ArtistPage';
import { CreateArtistPage } from '@/features/artists/CreateArtistPage';
import { MyArtistsPage } from '@/features/artists/MyArtistsPage';
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage';
import { LoginPage } from '@/features/auth/LoginPage';
import { RegisterPage } from '@/features/auth/RegisterPage';
import { ResetPasswordPage } from '@/features/auth/ResetPasswordPage';
import { VerifyEmailPage } from '@/features/auth/VerifyEmailPage';
import { FeedPage } from '@/features/feed/FeedPage';
import { PostPage } from '@/features/feed/PostPage';
import { CloseFriendsPage } from '@/features/profile/CloseFriendsPage';
import { PostComposerPage } from '@/features/posts/PostComposerPage';
import { LibraryPage } from '@/features/playlists/LibraryPage';
import { ProfilePage } from '@/features/profile/ProfilePage';
import { SearchPage } from '@/features/search/SearchPage';
import { FollowListPage } from '@/features/social/FollowListPage';
import { SettingsPage } from '@/features/profile/SettingsPage';
import { TrackPage } from '@/features/tracks/TrackPage';
import { UploadPage } from '@/features/tracks/UploadPage';
import { AppLayout } from './AppLayout';
import { GuestOnly, RequireAuth } from './guards';
import { NotFoundPage } from './NotFoundPage';
import { SearchRedirect } from './SearchRedirect';

// Route map and active tab: see ./navigation.ts (W-38).
const appChildren: RouteObject[] = [
  { index: true, element: <FeedPage which="for-you" /> },
  { path: 'following', element: <FeedPage which="following" /> },
  { path: 'posts/new', element: <PostComposerPage /> },
  { path: 'posts/:id', element: <PostPage /> },
  { path: 'discover', element: <SearchPage /> },
  { path: 'upload', element: <UploadPage /> },
  { path: 'me/artists', element: <MyArtistsPage /> },
  { path: 'me/artists/new', element: <CreateArtistPage /> },
  { path: 'settings', element: <SettingsPage /> },
  { path: 'settings/close-friends', element: <CloseFriendsPage /> },
  { path: 'u/:username', element: <ProfilePage /> },
  { path: 'u/:username/followers', element: <FollowListPage which="followers" /> },
  { path: 'u/:username/following', element: <FollowListPage which="following" /> },
  { path: 'library', element: <LibraryPage /> },
  // dnd-kit only loads with the playlist page.
  {
    path: 'p/:id',
    lazy: async () => ({
      Component: (await import('@/features/playlists/PlaylistPage')).PlaylistPage,
    }),
  },
  { path: 'search', element: <SearchRedirect /> },
  { path: 'a/:slug', element: <ArtistPage /> },
  { path: 't/:id', element: <TrackPage /> },
  { path: '*', element: <NotFoundPage /> },
];

export const routes: RouteObject[] = [
  // Mock-only: emails "sent" by MSW, reachable logged in or out.
  ...(import.meta.env.VITE_MOCKS === '1' || import.meta.env.VITE_MOCKS === 'true'
    ? [
        {
          path: '/dev/mailbox',
          lazy: async () => ({
            Component: (await import('@/features/dev/MailboxPage')).MailboxPage,
          }),
        },
      ]
    : []),
  {
    path: '/login',
    element: (
      <GuestOnly>
        <LoginPage />
      </GuestOnly>
    ),
  },
  {
    path: '/register',
    element: (
      <GuestOnly>
        <RegisterPage />
      </GuestOnly>
    ),
  },
  { path: '/forgot-password', element: <ForgotPasswordPage /> },
  { path: '/reset-password', element: <ResetPasswordPage /> },
  { path: '/verify-email', element: <VerifyEmailPage /> },
  {
    path: '/',
    element: (
      <RequireAuth>
        <AppLayout />
      </RequireAuth>
    ),
    children: appChildren,
  },
];

export function createAppRouter() {
  return createBrowserRouter(routes);
}
