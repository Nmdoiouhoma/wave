// FB-09: clips only make sense for public tracks. The control is revealed for `public` only;
// while hidden the value is kept (and still sent), so going back to public restores it.
import type { Visibility } from '@/api/types';
import { cx } from '@/components/ui/cx';
import { Toggle } from '@/components/ui/Field';

export const ALLOW_CLIPS_LABEL =
  'Autoriser les autres à utiliser un extrait de ce morceau dans leurs posts';
export const ALLOW_CLIPS_HELP =
  'Comme un son TikTok : ils pourront reprendre 15 à 60 s de ton morceau dans le feed. Tu peux changer d’avis à tout moment.';

export function AllowClipsField({
  visibility,
  checked,
  onChange,
  disabled,
}: {
  visibility: Visibility;
  checked: boolean;
  onChange: (v: boolean) => void;
  disabled?: boolean;
}) {
  const shown = visibility === 'public';
  return (
    // grid-rows 0fr → 1fr animates the real height: smooth reveal without a jump.
    <div
      className={cx(
        'grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none',
        shown ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0',
      )}
      aria-hidden={!shown}
      inert={!shown}
      data-testid="allow-clips"
    >
      <div className="min-h-0 overflow-hidden">
        <Toggle
          label={ALLOW_CLIPS_LABEL}
          description={ALLOW_CLIPS_HELP}
          checked={checked}
          onChange={onChange}
          disabled={disabled}
        />
      </div>
    </div>
  );
}
