$(document).ready(function () {
  if (Backdrop.settings.openaiAlt) {
    var wrapperId = Backdrop.settings.openaiAlt.wrapper_id;

    console.log("✅ Auto-generation script loaded and ready.");

    // Listen for file uploads.
    $(document).on('change', 'input[name="files[fid]"]', function () {
      var checkForFid = setInterval(function () {
        var fid = $('input[name="fid[fid]"]').val();
        if (fid && fid !== "0") {
          console.log("🖼️ Detected uploaded image with FID:", fid);
          clearInterval(checkForFid);
          triggerAutoGenerate(fid, null, wrapperId);
        }
      }, 500);
    });

    // Listen for image selection from the library.
    $(document).on('click', '.image-library-choose-file', function () {
      var selectedImage = $(this).find('img');
      var fid = selectedImage.data('fid');
      var src = selectedImage.data('file-url');

      console.log("📚 Image selected from library:", src, "FID:", fid);
      triggerAutoGenerate(fid, src, wrapperId);
    });

    // Before inserting the image, ensure the alt text and file ID are set.
    $(document).on('click', '.editor-dialog .form-actions input[type="submit"]', function () {
      var altText = $('input[name="attributes[alt]"]').val();
      var fid = $('input[name="fid[fid]"]').val();
      var imgSrc = $('input[name="attributes[src]"]').val();

      console.log("🔍 Preparing to insert image with src:", imgSrc);

      if (altText) {
        console.log("✅ Alt text to be inserted:", altText);
      } else {
        console.warn("⚠️ No alt text found.");
      }

      if (fid) {
        console.log("📁 File ID to be inserted:", fid);
      } else {
        console.warn("⚠️ No file ID found.");
      }

      // Set the data attributes to ensure CKEditor includes them.
      if (imgSrc) {
        var imgElement = $('img[src="' + imgSrc + '"]');

        if (imgElement.length) {
          imgElement.attr('alt', altText);
          if (fid) {
            imgElement.attr('data-file-id', fid);
          }
        }
      }
    });
  }
});

function triggerAutoGenerate(fid, src, wrapperId) {
  $.ajax({
    url: Backdrop.settings.basePath + 'openai-alt/ckeditor-autogenerate',
    type: 'POST',
    data: {
      fid: fid,
      src: src,
    },
    success: function (response) {
      console.log("✅ AJAX request successful! Full response:", response);
      if (response.status === "success" && response.alt_text) {
        $('#' + wrapperId + ' input[name="attributes[alt]"]').val(response.alt_text);
        console.log("✅ Alt text successfully inserted in form.");
      } else {
        console.error("❌ No valid alt text received.");
      }
    },
    error: function (xhr, status, error) {
      console.error("❌ AJAX request failed:", status, error);
    }
  });
}
