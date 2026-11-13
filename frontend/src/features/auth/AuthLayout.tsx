import type { ReactNode } from 'react';
import { Link } from 'react-router';

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
      <main className="relative w-full max-w-sm">
        <Link
          to="/"
          className="mb-8 flex items-center justify-center gap-2"
          aria-label="Wave, accueil"
        >
          <span className="text-4xl leading-none font-bold tracking-[-0.04em]">
            wave<span className="text-accent">.</span>
          </span>
        </Link>
        <div className="bg-surface border-line rounded-[28px] border p-6 sm:p-8">
          <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
          {subtitle && <p className="text-muted mt-1 text-sm">{subtitle}</p>}
          <div className="mt-6">{children}</div>
        </div>
        {footer && <div className="text-muted mt-6 text-center text-sm">{footer}</div>}
      </main>
    </div>
  );
}
