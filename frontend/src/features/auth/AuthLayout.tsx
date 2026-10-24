import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { WaveLogo } from '@/components/ui/icons';

export function AuthLayout({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string;
  subtitle?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <div className="relative flex min-h-dvh flex-col items-center justify-center overflow-hidden px-4 py-10">
      <div
        aria-hidden
        className="bg-accent/20 pointer-events-none absolute -top-40 left-1/2 size-[36rem] -translate-x-1/2 rounded-full blur-3xl"
      />
      <div
        aria-hidden
        className="bg-cyan/10 pointer-events-none absolute -bottom-48 -left-24 size-96 rounded-full blur-3xl"
      />
      <main className="relative w-full max-w-sm">
        <Link
          to="/"
          className="mb-8 flex items-center justify-center gap-2"
          aria-label="Wave, accueil"
        >
          <span className="bg-wave flex size-10 items-center justify-center rounded-xl text-white">
            <WaveLogo size={24} />
          </span>
          <span className="text-2xl font-bold tracking-tight">Wave</span>
        </Link>
        <div className="bg-surface/80 ring-line rounded-3xl p-6 ring-1 backdrop-blur sm:p-8">
          <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
          {subtitle && <p className="text-muted mt-1 text-sm">{subtitle}</p>}
          <div className="mt-6">{children}</div>
        </div>
        {footer && <div className="text-muted mt-6 text-center text-sm">{footer}</div>}
      </main>
    </div>
  );
}
