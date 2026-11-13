import { useNavigate } from 'react-router';
import { useLogout } from '@/api/queries/auth';
import { Button } from '@/components/ui/Button';
import { LogoutIcon } from '@/components/ui/icons';

/** FB-25: logout reachable from the shell (desktop rail, own profile on phones). */
export function LogoutButton({
  size = 'sm',
  className,
}: {
  size?: 'sm' | 'md';
  className?: string;
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
      onClick={() =>
        logout.mutate(undefined, { onSettled: () => void navigate('/login', { replace: true }) })
      }
    >
      Se déconnecter
    </Button>
  );
}
