/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */
swarm.mentions = {
    additionalClass: '',
    mode: '',
    mentionsArray: [],
    enableGroups: true,

    init: function(additionalClass) {
        this.mode = swarm.comments.mentionsMode;
        this.mentionsArray = swarm.comments.mentionsArray;
        this.additionalClass = additionalClass || 'no-class';

        if (this.mode === 'global') {
            this.getAllUsersAndGroups();
        } else if (this.mode === 'projects') {
            if (this.mentionsArray.length === 0) {
                this.getAllUsersAndGroups();
            } else {
                this.addUsersAndGroups(this.mentionsArray);
            }
        }
    },
    addUsersAndGroups: function (){
        $('.comment-form textarea').userMultiPicker({
            inputName:      'Users',
            groupInputName: 'Groups',
            selectedGroups: [],
            excludeProjects: true,
            enableGroups: this.enableGroups,
            triggerChar: '@',
            groupTriggerChar: '@@',
            triggerModifiers: '[!*]',
            minLength: 1,
            onCaret: true,
            clearInput: false,
            disabled: true,
            items: 5,
            source: this.mentionsArray,
            additionalClass : this.additionalClass,
            consumeEnterKeyPress: false,
            onSelect: function() {
                var currentText = this.typeahead.$element.val();
                var activeItem = this.typeahead.$menu.find('.active').data('value');
                if (activeItem) {
                    var pattern = new RegExp("(" + this.options.triggerChar + "+[^\\s]{" + this.options.minLength + ",})$");
                    var cursorIndex = this.typeahead.$element.get(0).selectionStart;
                    var firstPart = currentText.substring(0, cursorIndex);
                    var newValue;
                    if (activeItem.type === 'group') {
                        newValue = firstPart.replace(pattern, this.options.triggerChar + this.options.triggerChar + activeItem.id);
                    } else {
                        newValue = firstPart.replace(pattern, this.options.triggerChar + activeItem.id);
                    }
                    var cursorPosition = newValue.length;
                    newValue += currentText.substring(cursorIndex);

                    this.typeahead.$element.val(newValue);
                    this.typeahead.$element.setCursorPosition(cursorPosition);
                }
            }
        });
    },
    getAllUsersAndGroups: function() {
        $('.comment-form textarea').userMultiPicker({
            inputName:      'Users',
            groupInputName: 'Groups',
            selectedGroups: [],
            excludeProjects: true,
            enableGroups: this.enableGroups,
            triggerChar: '@',
            groupTriggerChar: '@@',
            triggerModifiers: '[!*]',
            minLength: 1,
            onCaret: true,
            clearInput: false,
            respectBlacklist: true,
            items: 5,
            additionalClass : this.additionalClass,
            consumeEnterKeyPress: false,
            onSelect: function() {
                var currentText = this.typeahead.$element.val();
                var activeItem = this.typeahead.$menu.find('.active').data('value');
                if (activeItem) {
                    var pattern = new RegExp("(" + this.options.triggerChar + "+[^\\s]{" + this.options.minLength + ",})$");
                    var cursorIndex = this.typeahead.$element.get(0).selectionStart;
                    var firstPart = currentText.substring(0, cursorIndex);
                    var newValue;
                    if (activeItem.type === 'group') {
                        newValue = firstPart.replace(pattern, this.options.triggerChar + this.options.triggerChar + activeItem.id);
                    } else {
                        newValue = firstPart.replace(pattern, this.options.triggerChar + activeItem.id);
                    }
                    var cursorPosition = newValue.length;
                    newValue += currentText.substring(cursorIndex);

                    this.typeahead.$element.val(newValue);
                    this.typeahead.$element.setCursorPosition(cursorPosition);
                }
            }
        });
    }
};

