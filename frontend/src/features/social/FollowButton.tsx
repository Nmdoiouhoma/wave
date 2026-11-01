import { useFollowState, useToggleFollow } from '@/api/queries/social';
import type { FollowState } from '@/api/types';
import { Button } from '@/components/ui/Button';
import { errorMessage } from '@/lib/errorMessages';
import { toast } from '@/stores/toast';

/** Optimistic follow/unfollow; the count it changes is shared with profile, lists and search. */
export function FollowButton({
  username,
  displayName,
  server,
  size = 'sm',
}: {
  username: string;
  displayName: string;
  server: FollowState;
  size?: 'sm' | 'md';
}) {
  const state = useFollowState(username, server);
  const toggle = useToggleFollow();
  return (
    <Button
      size={size}
      variant={state.following ? 'secondary' : 'primary'}
      aria-pressed={state.following}
      aria-label={state.following ? `Ne plus suivre ${displayName}` : `Suivre ${displayName}`}
      disabled={toggle.isPending}
      onClick={() =>
        toggle.mutate(
          { username, follow: !state.following, current: state },
          { onError: (e) => toast(errorMessage(e), 'error') },
        )
      }
    >
      {state.following ? 'Abonné·e' : 'Suivre'}
    </Button>
  );
}
