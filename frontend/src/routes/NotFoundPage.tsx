import { ButtonLink } from '@/components/ui/Button';

export function NotFoundPage() {
  return (
    <div className="flex min-h-[50vh] flex-col items-center justify-center gap-4 text-center">
      <p className="text-wave text-6xl font-black">404</p>
      <p className="text-muted">Cette page n’existe pas (ou plus).</p>
      <ButtonLink to="/" variant="secondary">
        Retour à l’accueil
      </ButtonLink>
    </div>
  );
}
