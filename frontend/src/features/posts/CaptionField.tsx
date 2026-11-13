// W-35: caption with live counter and `#hashtag` highlighting. Highlighting is a text overlay
// (React text nodes, never HTML) stacked under a transparent textarea.
import { useId } from 'react';
import { cx } from '@/components/ui/cx';
import { CAPTION_MAX, captionLength, splitHashtags } from './postRules';

export function CaptionField({
  value,
  onChange,
  disabled,
}: {
  value: string;
  onChange: (v: string) => void;
  disabled?: boolean;
}) {
  const id = useId();
  const count = captionLength(value);
  const over = count > CAPTION_MAX;
  const box =
    'col-start-1 row-start-1 min-h-28 w-full px-4 py-3 text-base leading-6 break-words whitespace-pre-wrap';
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-fg/90 text-sm font-medium">
        Légende (facultatif)
      </label>
      <div
        className={cx(
          'bg-surface-2 ring-line focus-within:ring-accent grid rounded-xl ring-1 focus-within:ring-2',
          over && 'ring-danger',
        )}
      >
        <div aria-hidden data-testid="caption-highlight" className={cx(box, 'pointer-events-none')}>
          {splitHashtags(value).map((part, i) =>
            part.tag ? (
              <span key={i} className="text-accent font-semibold">
                {part.text}
              </span>
            ) : (
              <span key={i}>{part.text}</span>
            ),
          )}
          {'\n'}
        </div>
        <textarea
          id={id}
          value={value}
          disabled={disabled}
          rows={4}
          placeholder="Dis-en un peu plus… #nouveausound"
          aria-invalid={over}
          aria-describedby={`${id}-count`}
          onChange={(e) => onChange(e.target.value)}
          className={cx(
            box,
            'caret-fg placeholder:text-faint resize-none overflow-hidden bg-transparent text-transparent focus:outline-none',
          )}
        />
      </div>
      <p
        id={`${id}-count`}
        className={cx('text-xs', over ? 'text-danger' : 'text-faint')}
        aria-live="polite"
      >
        {`${count} / ${CAPTION_MAX}`}
        {over && ' : légende trop longue'}
      </p>
    </div>
  );
}
