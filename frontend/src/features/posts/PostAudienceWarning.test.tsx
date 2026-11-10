import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { PostAudienceWarning } from './PostAudienceWarning';

const CHANGE = 'Si tu changes l’audience du morceau, l’extrait suivra.';

describe('post composer audience warning (W-48, mounted by W-35)', () => {
  it.each([
    ['followers', 'Visible seulement par tes abonnés'],
    ['close_friends', 'Visible seulement par tes amis proches'],
    ['private', 'Visible seulement par toi seul'],
  ] as const)('own %s track', (visibility, text) => {
    render(<PostAudienceWarning track={{ isOwner: true, visibility }} />);
    expect(screen.getByText(text)).toBeInTheDocument();
    expect(screen.getByText(CHANGE)).toBeInTheDocument();
  });

  it.each([
    [{ isOwner: true, visibility: 'public' }],
    [{ isOwner: false, visibility: 'public' }],
    [{ isOwner: false, visibility: null }],
  ] as const)('absent for %o', (track) => {
    const { container } = render(<PostAudienceWarning track={track} />);
    expect(container).toBeEmptyDOMElement();
  });
});
