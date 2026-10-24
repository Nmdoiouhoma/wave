import { createBrowserRouter, type RouteObject } from 'react-router';
import { ArtistPage } from '@/features/artists/ArtistPage';
import { MyArtistsPage } from '@/features/artists/MyArtistsPage';
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage';
import { LoginPage } from '@/features/auth/LoginPage';
import { RegisterPage } from '@/features/auth/RegisterPage';
import { ResetPasswordPage } from '@/features/auth/ResetPasswordPage';
import { VerifyEmailPage } from '@/features/auth/VerifyEmailPage';
import { ProfilePage } from '@/features/profile/ProfilePage';
import { SettingsPage } from '@/features/profile/SettingsPage';
import { TrackPage } from '@/features/tracks/TrackPage';
import { UploadPage } from '@/features/tracks/UploadPage';
import { AppLayout } from './AppLayout';
import { GuestOnly, RequireAuth } from './guards';
import { HomePage } from './HomePage';
import { NotFoundPage } from './NotFoundPage';

const appChildren: RouteObject[] = [
  { index: true, element: <HomePage /> },
  { path: 'upload', element: <UploadPage /> },
  { path: 'me/artists', element: <MyArtistsPage /> },
  { path: 'settings', element: <SettingsPage /> },
  { path: 'u/:username', element: <ProfilePage /> },
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
