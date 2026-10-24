import { IconButton } from '@/components/ui/Button';
import { VolumeIcon } from '@/components/ui/icons';
import { usePlayer } from '@/stores/player';

export function VolumeControl() {
  const volume = usePlayer((s) => s.volume);
  const muted = usePlayer((s) => s.muted);
  const setVolume = usePlayer((s) => s.setVolume);
  const toggleMute = usePlayer((s) => s.toggleMute);
  const effective = muted ? 0 : volume;
  return (
    <div className="flex w-full items-center gap-1">
      <IconButton
        label={muted ? 'Réactiver le son' : 'Couper le son'}
        onClick={toggleMute}
        className="size-9"
      >
        <VolumeIcon size={18} muted={muted || volume === 0} />
      </IconButton>
      <input
        type="range"
        className="range"
        min={0}
        max={100}
        value={Math.round(effective * 100)}
        onChange={(e) => setVolume(Number(e.target.value) / 100)}
        aria-label="Volume"
        aria-valuetext={`${Math.round(effective * 100)} %`}
        style={{ ['--pct' as string]: `${effective * 100}%` }}
      />
    </div>
  );
}
