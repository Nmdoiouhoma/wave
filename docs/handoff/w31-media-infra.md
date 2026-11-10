# W-31 — media-infra (clip et visuel d'un post)

## Fait
- `GenerateClipHandler` + `ClipProcessor` : coupe le m4a traité du morceau (`-ss/-t` en entrée), fondus 300 ms (`afade`), AAC-LC 160 k 44,1 kHz, faststart, pas de second loudnorm ; durée vérifiée à ±50 ms, sinon `processing_error`. Produit pour tout post, sans regarder l'audience (FB-18). Clé `audio/clips/{postId}.m4a`.
- `ProcessPostVisualHandler` + `PostVisualProcessor` : image → WebP ≤ 1080×1920 (ImageProcessor, variante PostVisual) ; vidéo → H.264 main CRF 26, ≤ 720×1280 sans agrandissement (côtés pairs), CFR ≤ 30 ips, `-an`, faststart, + poster WebP de la 1re image. Durcissement : liste blanche de démuxeurs, `file:`, codecs d'entrée (h264/hevc/mpeg4/vp9/av1), 50 Mpx, ≤ 16 flux, 15 Mo, lecture plafonnée à 61 s et durée réellement décodée mesurée (> 60 s → `too_long`), `-threads 2` (sous prlimit 768 Mo, 4K testé). Clés publiques déterministes par post (`MediaKeys::postVisual`, uuid v5) : une relivraison écrase, aucun orphelin.
- Passage `ready` : sous verrou de ligne (`LockedFind`), chaque handler écrit sa clé puis appelle `Post::markReadyIfComplete()` dans la même transaction ; l'ordre d'arrivée n'importe pas (testé dans les deux sens). Un échec d'un handler → `failed` + motif (`PostMediaFailure`), l'autre saute. Relivraison : no-op, ou simple revérification de `ready` si la clé est déjà enregistrée.
- Post supprimé (ou déjà `failed`) pendant le traitement : le handler purge la clé qu'il vient d'écrire (clip, ou vidéo + poster) et le brut de quarantaine (revue qa vague 1, bas 3). Brut supprimé au succès, gardé après rejet (CleanupQuarantine).
- `MarkMediaFailedOnFinalFailure` couvre aussi les deux messages (échec final → post `failed`/`processing_error`).
- Tests : `tests/Media/PostMediaHandlersTest.php` (22, ffmpeg réel) : durée exacte + fondus, deux ordres, échec de l'un puis saut de l'autre, relivraison, post supprimé en cours, 70 s → `too_long`, audio seul / PNG renommé / faux ftyp / playlist ffconcat → `invalid_media`, 4K, pas d'agrandissement, ffprobe (un seul flux, h264 Main, ips, dimensions).

## Décisions prises
- Pas de `PostRepository::tryMarkReady` (SQL, non testable sans base dans test-media) : transition sous verrou via la méthode d'entité. `tryMarkReady` reste en place, plus appelée.
- Hors périmètre strict, ajouts additifs : `Post::markReadyIfComplete()` (Post.php), champ `fps` dans `Probe::videoStreams`, `FfmpegRunner::VIDEO_DEMUXERS`, `MediaKeys::postClip/postVisual`.

## Contrat d'API modifié ?
- Non.

## Reste à faire / points ouverts
- Le verrou de ligne réel (deux workers) n'est pas testé en processus parallèles : même mécanisme que ProcessTrack ; à ajouter à ConcurrencySecurityTest si qa le veut.
- Si W-32/W-39 modifient Post.php, conserver `markReadyIfComplete`.

## Commande pour vérifier
`make test-media c="--no-progress --filter PostMediaHandlersTest"` puis `bash .claude/skills/wave-verif/verif.sh back`
