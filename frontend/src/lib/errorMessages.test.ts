import { describe, expect, it } from 'vitest';
import { failureMessage } from './errorMessages';

describe('failureMessage (FB-10)', () => {
  it('no_audio depends on whether the source was a video', () => {
    expect(failureMessage('no_audio', { video: true })).toBe(
      'Cette vidéo ne contient pas de son : il n’y a rien à importer.',
    );
    expect(failureMessage('no_audio')).toBe('Ce fichier ne contient pas de son.');
  });
  it('invalid_media is explicit about supported formats', () => {
    expect(failureMessage('invalid_media')).toBe(
      'Ce fichier est illisible ou n’est pas un format audio/vidéo pris en charge (MP3, M4A, AAC, MP4).',
    );
  });
  it('unknown reasons fall back to a generic message', () => {
    expect(failureMessage('something_new')).toBe('Le traitement a échoué.');
  });
});
