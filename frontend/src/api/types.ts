// Aliases over the generated OpenAPI types (never hand-write API shapes).
import type { components, operations } from './schema';

type S = components['schemas'];

export type Problem = S['Problem'];
export type ProblemCode = S['ProblemCode'];
export type Violation = S['Violation'];
export type AuthSession = S['AuthSession'];
export type RegisterRequest = S['RegisterRequest'];
export type LoginRequest = S['LoginRequest'];
export type PasswordResetConfirmRequest = S['PasswordResetConfirmRequest'];
export type Me = S['Me'];
export type UpdateMeRequest = S['UpdateMeRequest'];
export type ChangePasswordRequest = S['ChangePasswordRequest'];
export type UserSummary = S['UserSummary'];
export type UserProfile = S['UserProfile'];
export type Artist = S['Artist'];
export type ArtistSummary = S['ArtistSummary'];
export type CreateArtistRequest = S['CreateArtistRequest'];
export type UpdateArtistRequest = S['UpdateArtistRequest'];
export type Genre = S['Genre'];
export type Track = S['Track'];
export type TrackPage = S['TrackPage'];
export type UpdateTrackRequest = S['UpdateTrackRequest'];
export type Visibility = S['Visibility'];
export type MediaStatus = S['MediaStatus'];
export type FailureReason = S['FailureReason'];
export type StreamUrl = S['StreamUrl'];
export type ListenRequest = S['ListenRequest'];
export type ListenResult = S['ListenResult'];

export type CreateTrackUploadRequest = S['CreateTrackUploadRequest'];
export type TrackUploadSlot = S['TrackUploadSlot'];
export type CreateTrackRequest = S['CreateTrackRequest'];
/** Track metadata sent with `POST /tracks` (everything but the uploadId). */
export type TrackMetadata = Omit<CreateTrackRequest, 'uploadId'>;

export type CursorParams = NonNullable<operations['listUserTracks']['parameters']['query']>;
