if (typeof il == "undefined") {
	il = {};
}

il.ExtLink = {

	/**
	 * Linkify wrapper
	 */
	autolink: function (selector, link_class) {
		$(selector).linkify({
			validate: {
				url: (val) => /^https?:\/\//.test(val), // only allow URLs that begin with a protocol
				email: false // don't linkify emails
			}
		});
		if (typeof link_class !== "undefined") {
			$(selector).find('a.linkified').addClass(link_class);
		}

		$(selector).find("a.linkified[target='_blank']").attr("rel", "noreferrer noopener");
	}
}
