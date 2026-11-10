import { ButtonLink } from '@/components/ui/Button';
import { MusicIcon, PlusIcon } from '@/components/ui/icons';
import { EmptyState } from '@/components/ui/States';

/** W-38 placeholder for `/posts/:id`; W-34 replaces it with the single post viewer. */
export function PostViewerStubPage() {
  return (
    <div className="flex h-full items-center justify-center px-6">
      <EmptyState icon={<MusicIcon size={28} />} title="Extrait bientôt disponible">
        <p>La lecture des extraits arrive avec le fil.</p>
        <ButtonLink to="/" size="sm" variant="secondary" className="mt-3">
          Retour au fil
        </ButtonLink>
      </EmptyState>
    </div>
  );
}

/** W-38 placeholder for `/posts/new?trackId=`; W-35 replaces it with the composer. */
export function PostComposerStubPage() {
  return (
    <div className="mx-auto max-w-xl">
      <h1 className="text-2xl font-bold tracking-tight">Publier un extrait</h1>
      <EmptyState icon={<PlusIcon size={28} />} title="La publication arrive bientôt">
        <p>Tu pourras bientôt partager un extrait de tes morceaux.</p>
      </EmptyState>
    </div>
  );
}
