// Mock-only page: shows the emails "sent" by the MSW backend (verification, password reset).
import { useState } from 'react';
import { Link } from 'react-router';
import { db } from '@/mocks/db';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/States';
import { MailIcon } from '@/components/ui/icons';

export function MailboxPage() {
  const [, setTick] = useState(0);
  const mails = db.state.outbox;
  return (
    <div className="mx-auto max-w-2xl px-4 py-8">
      <Link to="/" className="text-muted hover:text-fg text-sm">
        ← Retour à Wave
      </Link>
      <div className="mt-4 flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight">Boîte mail de démo</h1>
        <Button variant="secondary" size="sm" onClick={() => setTick((t) => t + 1)}>
          Actualiser
        </Button>
      </div>
      <p className="text-muted mt-1 text-sm">E-mails simulés par les mocks (aucun envoi réel).</p>
      <ul className="mt-6 flex flex-col gap-3">
        {mails.map((m) => (
          <li key={m.at + m.link} className="bg-surface ring-line rounded-2xl p-4 ring-1">
            <p className="font-semibold">{m.subject}</p>
            <p className="text-muted text-xs">
              À {m.to} · {new Date(m.at).toLocaleString('fr-FR')}
            </p>
            <a
              href={m.link}
              className="text-accent-strong mt-2 block truncate text-sm hover:underline"
            >
              {m.link}
            </a>
          </li>
        ))}
      </ul>
      {!mails.length && <EmptyState icon={<MailIcon size={28} />} title="Aucun e-mail" />}
    </div>
  );
}
