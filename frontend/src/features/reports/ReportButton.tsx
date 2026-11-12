// W-36: "Signaler" button + sheet for pages (track, playlist, artist, profile).
import { useCallback, useState } from 'react';
import { IconButton } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import { FlagIcon } from '@/components/ui/icons';
import { Sheet } from '@/components/ui/Sheet';
import { ReportForm } from './ReportForm';
import { reportTitle, type ReportTarget } from './reportOptions';

export function ReportButton({ target, className }: { target: ReportTarget; className?: string }) {
  const [open, setOpen] = useState(false);
  const close = useCallback(() => setOpen(false), []);
  return (
    <>
      <IconButton
        label={reportTitle(target.type)}
        onClick={() => setOpen(true)}
        className={cx('ring-line ring-1', className)}
      >
        <FlagIcon size={18} />
      </IconButton>
      <Sheet open={open} title={reportTitle(target.type)} onClose={close}>
        <ReportForm target={target} onDone={close} />
      </Sheet>
    </>
  );
}
