/**
 * @provides javelin-behavior-diffusion-copy-fix-message
 * @requires javelin-behavior
 *           javelin-dom
 *           javelin-stratcom
 *           javelin-util
 *           zeroclipboard
 */

JX.behavior('diffusion-copy-fix-message', function(config) {
  var zc_client,
      sigil = 'copy-fix-message',
      copy_link = JX.DOM.scry(document.body, 'a', sigil).pop();

  ZeroClipboard.config({
    hoverClass: 'phabricator-action-view-hover'
  });

  zc_client = new ZeroClipboard(copy_link.parentElement);

  zc_client.on('ready', function (readyEvent) {
    zc_client.on('copy', function (event) {
      var node_data = JX.Stratcom.getData(copy_link);
      zc_client.setText(node_data['fix-message']);
    });

    zc_client.on('aftercopy', function (event) {
      copy_link.innerHTML += ' <strong>(copied)</strong>';

      setTimeout(function () {
        copy_link.innerHTML = copy_link.innerHTML.replace(/ <strong>\(copied\)<\/strong>$/, '');
      }, 2000);
    });
  });

  JX.Stratcom.listen(
    'click',
    sigil,
    function (e) {
      e.kill();
    });
});
