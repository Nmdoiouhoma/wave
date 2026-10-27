import { useMe } from '@/api/queries/me';

/**
 * Operations tagged `x-requires-verified-email` answer 403 `email_not_verified` for unverified
 * accounts. Returns the explanation to show next to a disabled action, or null when allowed.
 */
export function useVerifiedGate(action = 'faire cela'): string | null {
  const { data: me } = useMe();
  if (!me || me.emailVerified) return null;
  return `Confirme ton adresse e-mail pour ${action}.`;
}
