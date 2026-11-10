// FB-18, ADR 012: the four track audiences, their French labels and icons.
import type { ReactNode } from 'react';
import type { Visibility } from '@/api/types';
import { GlobeIcon, LockIcon, PeopleIcon, StarIcon } from '@/components/ui/icons';

export interface AudienceInfo {
  value: Visibility;
  label: string;
  /** One-line help under the choice. */
  help: string;
  icon: (size: number) => ReactNode;
}

export const AUDIENCES: readonly AudienceInfo[] = [
  {
    value: 'public',
    label: 'Public',
    help: 'Tout le monde sur Wave',
    icon: (s) => <GlobeIcon size={s} />,
  },
  {
    value: 'followers',
    label: 'Abonnés',
    // User decision (W-48): "followers" = anyone who taps Suivre, no approval in v1.
    help: 'Toute personne qui te suit',
    icon: (s) => <PeopleIcon size={s} />,
  },
  {
    value: 'close_friends',
    label: 'Amis proches',
    help: 'Les abonnés que tu choisis',
    icon: (s) => <StarIcon size={s} />,
  },
  {
    value: 'private',
    label: 'Moi seul',
    help: 'Personne d’autre que toi',
    icon: (s) => <LockIcon size={s} />,
  },
];

export const audienceInfo = (v: Visibility): AudienceInfo =>
  AUDIENCES.find((a) => a.value === v) ?? AUDIENCES[0]!;

/** "Visible seulement par …" target, for a non-public own track. */
export const AUDIENCE_TARGET: Record<Exclude<Visibility, 'public'>, string> = {
  followers: 'tes abonnés',
  close_friends: 'tes amis proches',
  private: 'toi seul',
};

export const AUDIENCE_CHANGE_NOTE = 'Tes extraits de ce morceau suivront cette audience.';
export const CLOSE_FRIENDS_PATH = '/settings/close-friends';
