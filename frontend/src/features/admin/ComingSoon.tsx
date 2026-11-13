import { EmptyState } from '@/components/ui/States';

export function ComingSoon({ title }: { title: string }) {
  return (
    <div>
      <h1 className="mb-4 text-2xl font-bold tracking-tight">{title}</h1>
      <EmptyState title="Bientôt disponible" />
    </div>
  );
}
