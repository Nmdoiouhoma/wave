import { useNavigate } from 'react-router';
import { useLogout } from '@/api/queries/auth';
import { Button } from '@/components/ui/Button';
import { LogoutIcon } from '@/components/ui/icons';

/** FB-25: logout reachable from the shell (desktop rail, own profile on phones). */
export function LogoutButton({
  size = 'sm',
  className,
  iconOnly = false,
}: {
  size?: 'sm' | 'md';
  className?: string;
  /** Folded desktop rail: the icon alone, the label stays for screen readers and the tooltip. */
  iconOnly?: boolean;
}) {
  const logout = useLogout();
  const navigate = useNavigate();
  return (
    <Button
      variant="secondary"
      size={size}
      className={className}
      icon={<LogoutIcon size={16} />}
      loading={logout.isPending}
      aria-label={iconOnly ? 'Se déconnecter' : undefined}
      title={iconOnly ? 'Se déconnecter' : undefined}
      onClick={() =>
        logout.mutate(undefined, { onSettled: () => void navigate('/login', { replace: true }) })
      }
    >
      {iconOnly ? null : 'Se déconnecter'}
    </Button>
  );
}
