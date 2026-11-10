// FB-18: 4-choice audience selector shared by the upload step and the track settings.
import { useId } from 'react';
import { Link } from 'react-router';
import { useCloseFriendCount } from '@/api/queries/closeFriends';
import type { Visibility } from '@/api/types';
import { cx } from '@/components/ui/cx';
import { AUDIENCE_CHANGE_NOTE, AUDIENCES, CLOSE_FRIENDS_PATH } from './audience';

function memberCount(data: { count: number; more: boolean } | undefined): string | null {
  if (!data) return null;
  if (data.more) return `${data.count}+ amis proches`;
  if (data.count === 0) return 'Aucun ami proche pour l’instant';
  return `${data.count} ami${data.count > 1 ? 's' : ''} proche${data.count > 1 ? 's' : ''}`;
}

export function AudienceField({
  value,
  onChange,
  initial,
  disabled,
}: {
  value: Visibility;
  onChange: (v: Visibility) => void;
  /** Saved audience of an existing track: changing it shows the note about its posts. */
  initial?: Visibility;
  disabled?: boolean;
}) {
  const name = useId();
  const labelId = `${name}-label`;
  const closeFriends = value === 'close_friends';
  const count = useCloseFriendCount(closeFriends);
  const countLabel = memberCount(count.data);

  return (
    <div className="flex flex-col gap-2">
      <span id={labelId} className="text-sm font-medium">
        Qui peut écouter ce morceau ?
      </span>
      <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-labelledby={labelId}>
        {AUDIENCES.map((a) => {
          const helpId = `${name}-${a.value}-help`;
          return (
            <label
              key={a.value}
              className={cx(
                'has-[:focus-visible]:ring-accent flex cursor-pointer flex-col rounded-xl px-4 py-3 ring-1 transition has-[:focus-visible]:ring-2',
                value === a.value ? 'bg-accent/10 ring-accent' : 'bg-surface-2 ring-line',
                disabled && 'cursor-not-allowed opacity-60',
              )}
            >
              <input
                type="radio"
                name={name}
                value={a.value}
                checked={value === a.value}
                onChange={() => onChange(a.value)}
                disabled={disabled}
                aria-label={a.label}
                aria-describedby={helpId}
                className="sr-only"
              />
              <span className="flex items-center gap-1.5 text-sm font-semibold">
                {a.icon(14)}
                {a.label}
              </span>
              <span id={helpId} className="text-muted text-xs">
                {a.help}
              </span>
            </label>
          );
        })}
      </div>
      {closeFriends && (
        <p className="text-muted flex flex-wrap items-center gap-x-2 text-xs">
          {countLabel && <span data-testid="close-friend-count">{countLabel}</span>}
          {/* New tab: the form being filled (files, edits) must survive the detour. */}
          <Link
            to={CLOSE_FRIENDS_PATH}
            target="_blank"
            rel="noopener"
            className="text-accent-strong font-semibold hover:underline"
          >
            Gérer mes amis proches
          </Link>
        </p>
      )}
      {initial !== undefined && initial !== value && (
        <p role="note" className="text-muted text-xs">
          {AUDIENCE_CHANGE_NOTE}
        </p>
      )}
    </div>
  );
}
