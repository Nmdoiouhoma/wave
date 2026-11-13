/** FB-30: the comment action on desktop scrolls to and focuses the side panel's comment field. */
export function focusSideComment(): boolean {
  const field = document.querySelector<HTMLTextAreaElement>(
    'aside[aria-label="Détails de l’extrait"] textarea',
  );
  if (!field) return false;
  field.scrollIntoView?.({ block: 'center' });
  field.focus();
  return true;
}
