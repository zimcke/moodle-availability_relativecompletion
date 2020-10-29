/**
 * JavaScript for form editing completion conditions.
 *
 * @module moodle-availability_relativecompletion-form
 */
M.availability_relativecompletion = M.availability_relativecompletion || {};

/**
 * @class M.availability_relativecompletion.form
 * @extends M.core_availability.plugin
 */
M.availability_relativecompletion.form = Y.Object(M.core_availability.plugin);

/**
 * Initialises this plugin.
 *
 * @method initInner
 * @param {Array} cms Array of objects containing cmid => name
 */
M.availability_relativecompletion.form.initInner = function(cms,isSection,isNewActivity) {
    this.cms = cms;
	this.isSection = isSection;
	this.isNewActivity = isNewActivity;
};

M.availability_relativecompletion.form.getNode = function(json) {
    // Create HTML structure.
    var html = '<span class="col-form-label p-r-1"> ' + M.util.get_string('title', 'availability_relativecompletion') + '</span>' +
               ' <span class="availability-group form-group"><label>' +
            '<span class="accesshide">' + M.util.get_string('label_cm', 'availability_relativecompletion') + ' </span>' +
            '<select class="custom-select" name="idtype" title="' + M.util.get_string('label_cm', 'availability_relativecompletion') + '">';

	if(!this.isSection){
		html += '<option value="1">' + M.util.get_string('previous_activity', 'availability_relativecompletion') + '</option>';
	} else {
		html += '<option value="0">' + M.util.get_string('previous_section', 'availability_relativecompletion') + '</option>';
	}
		
	if (!this.isSection){
		    html += '</select></label> <label><span class="accesshide">' +
                M.util.get_string('label_completion', 'availability_relativecompletion') +
            ' </span><select class="custom-select" ' +
                            'name="e" title="' + M.util.get_string('label_completion', 'availability_relativecompletion') + '">' +
            '<option value="1">' + M.util.get_string('option_complete', 'availability_relativecompletion') + '</option>' +
            '<option value="0">' + M.util.get_string('option_incomplete', 'availability_relativecompletion') + '</option>' +
            '<option value="2">' + M.util.get_string('option_pass', 'availability_relativecompletion') + '</option>' +
            '<option value="3">' + M.util.get_string('option_fail', 'availability_relativecompletion') + '</option>' +
            '</select></label></span>';
	} else {
			html += '</select></label> <label><span class="accesshide">' +
                M.util.get_string('label_completion', 'availability_relativecompletion') +
            ' </span><select class="custom-select" ' +
                            'name="e" title="' + M.util.get_string('label_completion', 'availability_relativecompletion') + '">' +
            '<option value="0">' + M.util.get_string('all_activities', 'availability_relativecompletion') + '</option>' +
			'<option value="1">' + M.util.get_string('one_activity', 'availability_relativecompletion') + '</option>' +
            '</select></label></span>';
	}
	
	if (this.cms.length > 0) {
		var cm = this.cms[0];
		if (!this.isSection) {
			html += '<br>' + M.util.get_string('current_previous_activity', 'availability_relativecompletion') + ': ' + cm.name; 
		} else {
			html += '<br>' + M.util.get_string('current_previous_section', 'availability_relativecompletion') + ': ' + cm.name; 
		}
	} else { // there is no previous section or activity at the moment
		if (!this.isSection) {
			if (!this.isNewActivity) {
				html += '<br>' + M.util.get_string('no_current_previous_activity', 'availability_relativecompletion'); 
			} else {
				html += '<br>' + M.util.get_string('creation_new_activity', 'availability_relativecompletion');
			}
		} else {
			html += '<br>' + M.util.get_string('no_current_previous_section', 'availability_relativecompletion');
		}
	}
    
	var node = Y.Node.create('<span class="form-inline">' + html + '</span>');

	if (json.idtype !== undefined) {
        node.one('select[name=idtype]').set('value', '' + json.idtype);
    }
	
    if (json.e !== undefined) {
        node.one('select[name=e]').set('value', '' + json.e);
    }
	
    // Add event handlers (first time only).
    if (!M.availability_relativecompletion.form.addedEvents) {
        M.availability_relativecompletion.form.addedEvents = true;
        var root = Y.one('.availability-field');
        root.delegate('change', function() {
            // Whichever dropdown changed, just update the form.
            M.core_availability.form.update();
        }, '.availability_relativecompletion select');
    }
	
    return node;
};

M.availability_relativecompletion.form.fillValue = function(value, node) {
	value.idtype = parseInt(node.one('select[name=idtype]').get('value'), 10);
    value.e = parseInt(node.one('select[name=e]').get('value'), 10);
};
