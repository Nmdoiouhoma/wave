import type { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement> & { size?: number };

function base({ size = 20, ...rest }: IconProps) {
  return {
    width: size,
    height: size,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 2,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    'aria-hidden': true,
    focusable: false,
    ...rest,
  };
}

export function PlayIcon(p: IconProps) {
  return (
    <svg {...base(p)} fill="currentColor" stroke="none">
      <path d="M7 4.5v15a1 1 0 0 0 1.5.86l12.5-7.5a1 1 0 0 0 0-1.72L8.5 3.64A1 1 0 0 0 7 4.5Z" />
    </svg>
  );
}
export function PauseIcon(p: IconProps) {
  return (
    <svg {...base(p)} fill="currentColor" stroke="none">
      <rect x="5" y="4" width="5" height="16" rx="1.5" />
      <rect x="14" y="4" width="5" height="16" rx="1.5" />
    </svg>
  );
}
export function NextIcon(p: IconProps) {
  return (
    <svg {...base(p)} fill="currentColor" stroke="none">
      <path d="M5 5.2v13.6a1 1 0 0 0 1.53.85L16 13.7V19a1 1 0 0 0 2 0V5a1 1 0 0 0-2 0v5.3L6.53 4.35A1 1 0 0 0 5 5.2Z" />
    </svg>
  );
}
export function PrevIcon(p: IconProps) {
  return (
    <svg {...base(p)} fill="currentColor" stroke="none">
      <path d="M19 5.2v13.6a1 1 0 0 1-1.53.85L8 13.7V19a1 1 0 0 1-2 0V5a1 1 0 0 1 2 0v5.3l9.47-5.95A1 1 0 0 1 19 5.2Z" />
    </svg>
  );
}
export function HomeIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z" />
    </svg>
  );
}
export function UploadIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M12 16V4m0 0-5 5m5-5 5 5M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
    </svg>
  );
}
export function MicIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <rect x="9" y="2" width="6" height="12" rx="3" />
      <path d="M5 11a7 7 0 0 0 14 0M12 18v4" />
    </svg>
  );
}
export function UserIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <circle cx="12" cy="8" r="4" />
      <path d="M4 21a8 8 0 0 1 16 0" />
    </svg>
  );
}
export function MusicIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M9 18V5l12-2v13" />
      <circle cx="6" cy="18" r="3" />
      <circle cx="18" cy="16" r="3" />
    </svg>
  );
}
export function ChevronDownIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="m6 9 6 6 6-6" />
    </svg>
  );
}
export function CloseIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M18 6 6 18M6 6l12 12" />
    </svg>
  );
}
export function VolumeIcon(p: IconProps & { muted?: boolean }) {
  const { muted, ...rest } = p;
  return (
    <svg {...base(rest)}>
      <path d="M11 5 6 9H3v6h3l5 4z" />
      {muted ? (
        <path d="m22 9-6 6m0-6 6 6" />
      ) : (
        <path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" />
      )}
    </svg>
  );
}
export function QueueIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M3 6h13M3 12h13M3 18h9M19 14v7m-3-3 3 3 3-3" />
    </svg>
  );
}
export function PlusIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M12 5v14M5 12h14" />
    </svg>
  );
}
export function TrashIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0-1 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 6" />
    </svg>
  );
}
export function EditIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" />
    </svg>
  );
}
export function LockIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <rect x="4" y="11" width="16" height="10" rx="2" />
      <path d="M8 11V7a4 4 0 0 1 8 0v4" />
    </svg>
  );
}
export function MailIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="m3 7 9 6 9-6" />
    </svg>
  );
}
export function CheckIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M20 6 9 17l-5-5" />
    </svg>
  );
}
export function AlertIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 8v5M12 16h.01" />
    </svg>
  );
}
export function LogoutIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />
    </svg>
  );
}
export function WaveLogo(p: IconProps) {
  return (
    <svg {...base(p)} strokeWidth={2.4}>
      <path d="M2 12c2.5 0 2.5-6 5-6s2.5 12 5 12 2.5-12 5-12 2.5 6 5 6" />
    </svg>
  );
}
export function HeartIcon(p: IconProps & { filled?: boolean }) {
  const { filled, ...rest } = p;
  return (
    <svg {...base(rest)} fill={filled ? 'currentColor' : 'none'}>
      <path d="M12 20.5s-7.5-4.6-9.3-9.2C1.5 8.2 3.4 5 6.6 5c2 0 3.3 1.1 4.1 2.3h2.6C14.1 6.1 15.4 5 17.4 5c3.2 0 5.1 3.2 3.9 6.3-1.8 4.6-9.3 9.2-9.3 9.2Z" />
    </svg>
  );
}
export function MoreIcon(p: IconProps) {
  return (
    <svg {...base(p)} fill="currentColor" stroke="none">
      <circle cx="5" cy="12" r="1.8" />
      <circle cx="12" cy="12" r="1.8" />
      <circle cx="19" cy="12" r="1.8" />
    </svg>
  );
}
export function SearchIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <circle cx="11" cy="11" r="7" />
      <path d="m20 20-3.5-3.5" />
    </svg>
  );
}
export function LibraryIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M4 4v16M9 4v16M14 4l6 16" />
    </svg>
  );
}
export function GripIcon(p: IconProps) {
  return (
    <svg {...base(p)} fill="currentColor" stroke="none">
      {[6, 12, 18].map((y) => (
        <g key={y}>
          <circle cx="9" cy={y} r="1.5" />
          <circle cx="15" cy={y} r="1.5" />
        </g>
      ))}
    </svg>
  );
}
export function ListPlusIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M3 6h12M3 12h12M3 18h7M18 15v6M15 18h6" />
    </svg>
  );
}
export function CompassIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <circle cx="12" cy="12" r="9" />
      <path d="m15.5 8.5-2 5-5 2 2-5z" />
    </svg>
  );
}

export function PeopleIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <circle cx="9" cy="8" r="3.5" />
      <path d="M2.5 20a6.5 6.5 0 0 1 13 0" />
      <path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.3a6.5 6.5 0 0 1 3.5 5.7" />
    </svg>
  );
}

export function StarIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1 6.2-5.5-2.9-5.5 2.9 1-6.2L3 9.6l6.2-.9z" />
    </svg>
  );
}

export function GlobeIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
    </svg>
  );
}

export function FlagIcon(p: IconProps) {
  return (
    <svg {...base(p)}>
      <path d="M5 21V4h11l-1.5 4L16 12H5" />
    </svg>
  );
}
