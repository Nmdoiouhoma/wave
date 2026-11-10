import { Link } from 'react-router';
import { cx } from '@/components/ui/cx';
import { HomeIcon } from '@/components/ui/icons';

/** W-38 placeholder for `/` and `/following`; W-34 replaces it with the vertical feed. */
export function FeedStubPage({ which }: { which: 'for-you' | 'following' }) {
  const tabs = [
    { to: '/', label: 'Pour toi', id: 'for-you' },
    { to: '/following', label: 'Abonnements', id: 'following' },
  ] as const;
  return (
    <div className="flex h-full flex-col items-center justify-center gap-6 px-6 text-center">
      <nav aria-label="Fils" className="flex gap-2">
        {tabs.map((t) => (
          <Link
            key={t.id}
            to={t.to}
            aria-current={t.id === which ? 'page' : undefined}
            className={cx(
              'rounded-full px-4 py-2 text-sm font-semibold',
              t.id === which ? 'bg-surface-2 text-fg' : 'text-muted hover:text-fg',
            )}
          >
            {t.label}
          </Link>
        ))}
      </nav>
      <span className="bg-wave flex size-14 items-center justify-center rounded-2xl text-white">
        <HomeIcon size={28} />
      </span>
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Le fil arrive bientôt</h1>
        <p className="text-muted mt-1 text-sm">
          Les extraits publiés par les gens que tu suis apparaîtront ici.
        </p>
      </div>
    </div>
  );
}
