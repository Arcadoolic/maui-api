{{--
    The crop tool of the "Change avatar" action (ViewPlayer, docs/DECISIONS.md D84): Filament's
    image editor opens with the whole picture selected and lets the square shrink to nothing.
    Here it starts from a square of the smallest size an avatar may have, in the middle of the
    picture, and never goes below it. In the picture's own pixels, which is what the editor's
    width and height fields show. The server refuses a smaller square whatever happens here
    (AvatarImage::MIN_SIDE).
--}}
<script data-avatar-crop>
    (() => {
        const MIN_SIDE = {{ (int) $minSide }};
        // Cropper.js keeps its instance on the image it edits, and its events bubble.
        const cropperOf = (event) => (event.target instanceof HTMLImageElement ? event.target.cropper : null);

        document.addEventListener('ready', (event) => {
            const cropper = cropperOf(event);
            if (!cropper) {
                return;
            }
            const image = cropper.getImageData();
            const side = Math.min(MIN_SIDE, image.naturalWidth, image.naturalHeight);
            cropper.setData({
                x: Math.round((image.naturalWidth - side) / 2),
                y: Math.round((image.naturalHeight - side) / 2),
                width: side,
                height: side,
            });
        });

        document.addEventListener('crop', (event) => {
            const cropper = cropperOf(event);
            if (!cropper) {
                return;
            }
            const image = cropper.getImageData();
            // A picture smaller than the minimum cannot be helped here: the server says so.
            if (Math.min(image.naturalWidth, image.naturalHeight) < MIN_SIDE) {
                return;
            }
            if (Math.round(event.detail.width) < MIN_SIDE || Math.round(event.detail.height) < MIN_SIDE) {
                cropper.setData({ width: MIN_SIDE, height: MIN_SIDE });
            }
        });
    })();
</script>
