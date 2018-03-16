/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */
swarm.project = {

    projectVars: {},

    initEdit: function(wrapper, saveUrl, projectId, enableGroups) {
        $.templates({
            defaultReviewerButton:
                '<div class="multipicker-item" data-value="{{>isRequired}}">'
                +       '<div class="pull-left">'
                +           '<div id="{{>id}}" class="btn-group">'
                +               '<button type="button" class="subform-forced btn btn-mini btn-info item-require {{if isRequired !== "false"}}active{{/if}}" data-toggle="{{if isGroup}}dropdown{{else}}button{{/if}}"'
                +                   ' title="{{if isRequired !== "false"}}{{te:"Make Vote Optional"}}{{else}}{{te:"Make Vote Required"}}{{/if}}" '
                +                   ' aria-label="{{if isRequired !== "false"}}{{te:"Make Vote Optional"}}{{else}}{{te:"Make Vote Required"}}{{/if}}">'
                +                   '<i class="{{if isRequired !== "false"}}icon-star{{else}}icon-star-empty{{/if}} icon-white{{if isRequired === "1"}} requiredOne {{else isRequired}} requiredAll {{/if}}">{{if isRequired === "1"}}{{:isRequired}}{{/if}}</i>'
                +                   '<input type="hidden" class="requirement preserve-value quorum" name="{{>inputName}}[{{>value}}][required]" value="{{>isRequired}}" />'
                +               '</button>'
                +               '{{if isGroup}}'
                +                   '<ul class="group-required item-require dropdown-menu">'
                +                       '<li class="optional"><a href="#" data-required="false"><i class="icon-star-empty"></i> {{te:"Make Vote Optional"}}</a></li>'
                +                       '<li class="one"><a href="#" data-required="1"><i class="icon-star">1</i> {{te:"Make One Vote Required"}}</a></li>'
                +                       '<li class="all"><a href="#" data-required="true"><i class="icon-star"></i> {{te:"Make All Votes Required"}}</a></li>'
                +                   '</ul>'
                +               '{{/if}}'
                +               '<button type="button" class="btn btn-mini btn-info button-name" disabled>{{>value}}</button>'
                +               '<button type="button" class="btn btn-mini btn-info item-remove"'
                +                       'title="{{te:"Remove"}}" aria-label="{{te:"Remove"}}">'
                +                   '<i class="icon-remove icon-white"></i>'
                +               '</button>'
                +           '</div>'
                +       '</div>'
                +   '</div>'
        });
        var membersElement   = $(wrapper).find('#members'),
            ownersElement    = $(wrapper).find('#owners'),
            reviewersElement = $(wrapper).find('#project-reviewers');

        swarm.project.projectVars.enableGroups = enableGroups;

        // setup userMultiPicker plugin for selecting members/owners
        membersElement.userMultiPicker({
            required:             true,
            itemsContainer:       $(wrapper).find('.members-list'),
            inputName:            'members',
            groupInputName:       'subgroups',
            enableGroups:         enableGroups,
            excludeProjects:      [projectId]
        });
        ownersElement.userMultiPicker({
            itemsContainer: $(wrapper).find('.owners-list'),
            inputName:      'owners',
            required:       function() {
                return $(wrapper).find('.checkbox-owners').prop('checked');
            }
        });
        var currentreviewers = $('.project-edit form').data('project').defaults.reviewers;
        var getReviewers = function(type, element){
            var currentreviewers = $(element).data('project').defaults.reviewers;
            return currentreviewers ? Object.keys(currentreviewers).filter(function (reviewer){
                return type === 'group'
                    ? (-1 !== reviewer.indexOf('swarm-group-'))
                    : (-1 === reviewer.indexOf('swarm-group-'));
            }) : [];
        };
        reviewersElement.userMultiPicker({
            current:              $(this),
            itemsContainer:       $(wrapper).find('.reviewers-list'),
            selected:             getReviewers('other', $('.project-edit form')),
            selectedGroups:       getReviewers('group', $('.project-edit form')),
            inputName:            "defaults[reviewers]",
            groupInputName:       "defaults[reviewers]",
            enableGroups:         enableGroups,
            useGroupKeys:         true,
            excludeProjects:      true,
            createItem:      function(value) {
                return $($.templates.defaultReviewerButton.render({
                    value: value,
                    id: value + '-project',
                    inputName: this.options.inputName,
                    isGroup:    value.indexOf('swarm-group-') !== -1,
                    isRequired: currentreviewers[value] && currentreviewers[value].required ? currentreviewers[value].required : "false"
                }));
            }
        });
        // enable reviewer required buttons
        $('form').on('click', '.control-group-reviewers div.type-user button.item-require', function(event){
            var $this = $(this);
            setTimeout(function() {
                var isRequired = $this.hasClass('active');

                $this.find('i').toggleClass('icon-star', isRequired).toggleClass('icon-star-empty', !isRequired);
                $this.find('input').val(isRequired);

                // temporarily show confirmation tooltip
                $this.attr('data-original-title', isRequired ? swarm.t('Vote Required') : swarm.t('Vote Optional') );
                $this.tooltip('show');

                // switch back to action verbiage shortly thereafter
                setTimeout(function(){
                    $this.attr('data-original-title', isRequired ? swarm.t('Make Vote Optional') : swarm.t('Make Vote Required') );
                }, 1000);
            }, 0);
        });

        $('form').on('click', '.control-group-reviewers .group-required a',function(event) {
            event.preventDefault();
            var option = $(this);
            var btnGroup = option.closest('.btn-group');
            var required = option.data('required');
            btnGroup.find('input.requirement').val(required);
            setTimeout(function() {

                var requiredButton = btnGroup.find('button.item-require');
                requiredButton.find('i')
                    .removeClass('icon-star icon-star-empty')
                    .addClass(required ? 'icon-star' : 'icon-star-empty')
                    .text(1 === required ? required : '');

                // Adjust the dropdown for the current value
                btnGroup.find('li').show();
                btnGroup.find('li a[data-required='+required+']').parent().hide();
                btnGroup.find('ul').hide();
                // temporarily show confirmation tooltip
                requiredButton.attr('data-original-title', required ? required === 1 ? swarm.t('One Vote Required') : swarm.t('Vote Required') : swarm.t('Vote Optional') );
                requiredButton.tooltip('show');

                // switch back to action verbiage shortly thereafter
                setTimeout(function(){
                    requiredButton.attr('data-original-title', swarm.t('Change Required Votes'));
                    requiredButton.tooltip('hide');
                }, 1000);
            }, 0);
        });

        $('form').on('click.reviewer','.control-group-reviewers div.type-group button.subform-forced', function(e){
            swarm.project.toggleDefaultMenu(e);
        });

        // when owners checkbox is clicked, update userMultiPicker required property and, if unchecked,
        // disable input element to prevent from sending data for selected owners when form is posted
        $(wrapper).find('.checkbox-owners').on('click', function(){
            var checked = $(wrapper).find('.checkbox-owners').prop('checked');

            $(wrapper).find('#owners').userMultiPicker('updateRequired');
            $(wrapper).find('.owners-list input').prop('disabled', !checked);
        });

        // wire up the member branches
        swarm.project.branch.init(wrapper);
        $(wrapper).find('.swarm-branch-group').on('click', swarm.project.branch.openNewSubForm);
        $(wrapper).on('click.swarm.branch.clear', '.branches .clear-branch-btn', function(e) {
            $(this).parent().find(".subform-identity-element").val('');
            swarm.project.branch.closeSubForm($(this).closest('.btn-group').find('.btn.dropdown-toggle'));
        });
        $(wrapper).on('click.swarm.branch.close', '.branches .close-branch-btn', function(e) {
            swarm.project.branch.closeSubForm($(this).closest('.btn-group').find('.btn.dropdown-toggle'));
        });

        // add help popover for the automated argument details
        $(wrapper).find('.automated-tests .help-details').popover({container: '.automated-tests', trigger: 'hover focus'});

        // add help popover for the automated deployment details
        $(wrapper).find('.automated-deployment .help-details').popover({container: '.automated-deployment', trigger: 'hover focus'});

        // check the form state and wire up the submit button
        swarm.form.checkInvalid($(wrapper).find('form'));
        $(wrapper).find('form').submit(function(e) {
            e.preventDefault();
            swarm.form.post(
                saveUrl,
                $(wrapper).find('form'),
                null,
                null,
                function (form) {
                    var formObject = $.deparam($(form).serialize());

                    // filter branches array to discard elements for removed branches
                    // these elements still contribute to the array length (with undefined values)
                    // and would otherwise be sent to the server
                    if ($.isArray(formObject.branches)) {
                        formObject.branches = formObject.branches.filter(function(value){
                            return value !== undefined;
                        });
                    }

                    // ensure we post owners and branches (unless they are read-only)
                    formObject = $.extend({owners: null}, formObject);
                    if (!formObject.branches && !$(form).find('.branches').is('.readonly')) {
                        formObject.branches = null;
                    }

                    // ensure we post members and subgroups (if they are enabled)
                    formObject = $.extend({members: null}, formObject);
                    if (enableGroups) {
                        formObject = $.extend({subgroups: null}, formObject);
                    }

                    return formObject;
                }
            );
        });

        // wire up project delete
        $(wrapper).find('.btn-delete').on('click', function(e){
            e.preventDefault();

            var button  = $(this);
            setTimeout(function() {
                var confirm = swarm.tooltip.showConfirm(button, {
                    placement:  'top',
                    content:    swarm.te('Delete this project?'),
                    buttons:    [
                        '<button type="button" class="btn btn-primary btn-confirm">' + swarm.te('Delete') + '</button>',
                        '<button type="button" class="btn btn-cancel">' + swarm.te('Cancel') + '</button>'
                    ]
                });

            // wire up cancel button
            confirm.tip().on('click', '.btn-cancel', function(){
                confirm.destroy();
            });

            // wire up delete button
            confirm.tip().on('click', '.btn-confirm', function(){
                // disable buttons when the delete is in progress
                swarm.form.disableButton(confirm.tip().find('.btn-confirm'));
                confirm.tip().find('.buttons .btn').prop('disabled', true);

                // attempt to delete the project via ajax request
                $.post('/projects/delete/' + encodeURIComponent(projectId), function(response) {
                    // if there is an error, present it in a new tooltip, otherwise
                    // redirect to the home page
                    if (response.isValid) {
                        window.location.href = swarm.url('/');
                    } else {
                        confirm.destroy();
                        var errorConfirm = swarm.tooltip.showConfirm(button, {
                            placement:  'top',
                            content:    response.error,
                            buttons:    [
                                '<button type="button" class="btn btn-primary">' + swarm.te('Ok') + '</button>'
                            ]
                        });
                        errorConfirm.tip().on('click', '.btn', function(){
                            errorConfirm.destroy();
                        });
                    }
                });
            });
                        }, 5);
        });

        // wire up project save to check user role and show a confirmation tooltip with a warning
        // message if the user won't be able to edit or access this project after save
        $(wrapper).find('.btn-save').on('click', function(e) {
            // no checks needed for admin users as they can access/edit any project
            if ($('body').hasClass('admin')) {
                return;
            }

            var message       = '',
                currentUserId = swarm.user.getAuthenticatedUser() ? swarm.user.getAuthenticatedUser().id : null,
                formObject    = $.deparam($(wrapper).find('form').serialize()),
                moderators    = $.map(formObject.branches || [], function (branch) { return branch.moderators || []; }),
                hasOwners     = $.isArray(formObject.owners),
                hasSubgroups  = $.isArray(formObject.subgroups),
                isMember      = $.inArray(currentUserId, formObject.members) !== -1,
                isOwner       = $.inArray(currentUserId, formObject.owners)  !== -1,
                isModerator   = $.inArray(currentUserId, moderators) !== -1,
                isPrivate     = formObject['private'] === "1";

            // at the moment we cannot determine from here if the user is a member of a group
            // to avoid false warnings, we have to assume the user is a member if members are specified via groups
            isMember = isMember || hasSubgroups;

            // user cannot edit project with owners if not an owner
            if (hasOwners && !isOwner) {
                message = '<p>' + swarm.te(
                    "You might not be able to edit this project later if you are not an owner."
                ) + '</p>';
            }

            // user cannot edit project with no owners if not a member
            if (!hasOwners && !isMember) {
                message = '<p>' + swarm.te(
                    "You might not be able to edit this project later if you are not a member."
                ) + '</p>';
            }

            // user cannot access private project if not a member, owner or branch moderator
            if (isPrivate && !isMember && !isOwner && !isModerator) {
                message += '<p>' + swarm.te(
                    "You might not be able to access this project later."
                    + " Only owners, members and branch moderators can access private projects."
                ) + '</p>';
            }

            if (!message) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            // show confirmation tooltip
            var button  = $(this),
                confirm = swarm.tooltip.showConfirm(button, {
                    placement:   'top',
                    customClass: 'project-save-tooltip',
                    content:     message,
                    buttons: [
                        '<button type="button" class="btn btn-primary btn-confirm">' + swarm.te('Continue') + '</button>',
                        '<button type="button" class="btn btn-cancel">' + swarm.te('Cancel') + '</button>'
                    ]
                });

            // disable form controls (we will re-enable them when confirmation tooltip is closed)
            var formControls = button.closest('.group-buttons').find('.btn');
            formControls.prop('disabled', true);

            // wire up cancel button
            confirm.tip().on('click', '.btn-cancel', function () {
                confirm.destroy();
                formControls.prop('disabled', false);
            });

            // wire up save button
            confirm.tip().on('click', '.btn-confirm', function () {
                confirm.destroy();
                $(wrapper).find('form').submit();
            });
        });

        // close the project-save warning popover when user clicks outside
        $(document).on('click', function(e) {
            if ($(e.target).closest('.project-save-tooltip').length === 0
                && $(wrapper).find('.btn-save').data('popover') !== undefined
            ) {
                e.preventDefault();
                $(wrapper).find('.btn-save').data('popover').destroy();
                $(wrapper).find('form .group-buttons .btn').prop('disabled', false);
            }
        });

        // initialize placeholder for post body
        var setPlaceholder = function() {
            var format      = $('select#postFormat').val(),
                placeholder = '';
            if (format === 'URL') {
                placeholder = 'foo=bar&baz=buzz';
            } else {
                placeholder = '{"foo" : "bar", "baz" : "buzz"}';
            }
            $('textarea#postBody').attr('placeholder', placeholder);
        };

        setPlaceholder();
        $('select#postFormat').on('change', setPlaceholder);

        var showEmailNotificationDetails = function() {
            var hidden = $('input#reviewEmails').prop('checked') || $('input#changeEmails').prop('checked');
            $('.email-flags').find('.help-block').toggleClass('hide', hidden);
        };

        showEmailNotificationDetails();
        $('.email-flags').find('input[type="checkbox"]').on('change', showEmailNotificationDetails);

        // ignore checkbox clicks during the collapsing animation for the corresponding submenu
        $('input[type=checkbox][data-toggle=collapse]').on('click', function(e){
            if ($(this).parent().siblings('div.body').hasClass('collapsing')) {
                e.preventDefault();
            }
        });
    },

    toggleDefaultMenu : function(e) {
        var button = $(e.target).closest('button');
        var menu = button.parent().find('.dropdown-menu');
        if ( menu && menu.length > 0) {
            menu.toggle();
        } else {
            $(button).button('toggle');
        }
    },

    branch: {
        _branchIndex: 0,

        init : function(wrapper) {
            // find any existing branches so we can init them and advance past their index
            $(wrapper).find('.control-group.branches .existing').each(function() {
                swarm.project.branch.initBranch(this);
                $(this).find('.btn.dropdown-toggle').dropdown();

                // advanced past this branch's index if its the highest we've seen
                var index = parseInt(
                    $(this).find('input.subform-identity-element')
                        .attr('name')
                        .match(/branches\[([0-9]+)\]/)[1],
                    10);
                if (index >= swarm.project.branch._branchIndex) {
                    swarm.project.branch._branchIndex = index + 1;
                }
            });

            // wire-up collapsing branch moderators
            // we can't use the default bootstrap's collapse plugin event as the on-click
            // event is stopped propagation due to our tweaks for drop-down menu
            $(wrapper).on('click.moderator.checkbox', '.checkbox-moderators', function() {
                $(this).closest('.control-group-moderators').find('.collapse').collapse(
                    $(this).prop('checked') ? 'show' : 'hide'
                );
            });

            // hide add-branch link if branches are read-only
            $(wrapper).find('.branches.readonly').find('.swarm-branch-link').hide();
        },

        initBranch : function(branch) {
            // add listener to new branch button
            $(branch).find('.btn.dropdown-toggle').on('click', function(e) {
                if ($(this).parent().hasClass('open')) {
                    swarm.project.branch.onCloseSubForm(this);
                    return;
                }
                $('.branches .open .btn.dropdown-toggle').not(this).each(function() {
                    swarm.project.branch.onCloseSubForm(this);
                });
                swarm.project.branch.onOpenSubForm(this);
            });

            // wire up close listener
            $('html').on('click.swarm.branchgroup.close', function(e) {
                swarm.project.branch.onCloseSubForm($(branch).find('.btn.dropdown-toggle'));
            });

            // prepare handler to check if branch sub-form is valid
            var checkBranchSubForm = function() {
                var branchButton = $(branch).closest('.branch-button'),
                    subForm      = branchButton.find('.dropdown-subform');

                swarm.form.checkInvalid(subForm);

                // highlight branch drop-down button and disable branch 'Done' button is subform is invalid
                branchButton.find('.btn.dropdown-toggle').toggleClass('btn-danger', subForm.is('.invalid'));
                subForm.find('.btn.close-branch-btn').prop('disabled', subForm.is('.invalid'));
            };

            // wire up required fields check in branch sub-form
            $(branch).find('input,textarea').on('input keyup blur', checkBranchSubForm);

            var reviewersElement       = $(branch).find('input.reviewers');
            var currentbranchreviewers = reviewersElement.data('selected');
            var filterReviewers = function(type, inputfield){
                return inputfield && inputfield.length > 0 ? Object.keys($(inputfield).data('selected')||{}).filter(function (reviewer){
                    return type === 'group'
                        ? (-1 !== reviewer.indexOf('swarm-group-'))
                        : (-1 === reviewer.indexOf('swarm-group-'));
                }) : [];
            };
            reviewersElement.userMultiPicker({
                itemsContainer:       $(branch).find('.branch-reviewers-list'),
                disabled:        $(branch).closest('.branches').is('.readonly'),
                selected:             filterReviewers('other', reviewersElement),
                selectedGroups:       filterReviewers('group', reviewersElement),
                enableGroups:         swarm.project.projectVars.enableGroups,
                useGroupKeys:         true,
                excludeProjects:      true,
                createItem:      function(value) {
                    return $($.templates.defaultReviewerButton.render({
                        value: value,
                        id: value  + '-' + $(branch).attr('id'),
                        inputName: this.options.inputName,
                        isGroup:    value.indexOf('swarm-group-') !== -1,
                        isRequired: currentbranchreviewers[value] && currentbranchreviewers[value].required ? currentbranchreviewers[value].required : "false"
                    }));
                }
            });

            // setup userMultiPicker plugin for selecting moderators
            var moderators = $(branch).find('input.input-moderators');
            moderators.userMultiPicker({
                itemsContainer:  $(branch).find('.moderators-list'),
                disabled:        $(branch).closest('.branches').is('.readonly'),
                enableGroups:    swarm.project.projectVars.enableGroups,
                excludeProjects: true,
                onUpdate:       function() {
                    checkBranchSubForm();

                    // update moderators info
                    var moderatorsList = $(branch).find('.checkbox-moderators:checked').length
                            ? $.map(this.getSelected(), function(value){ return value.label; })
                            : [],
                        infoText       = moderatorsList.length
                            ? swarm.tp('%s Moderator', '%s Moderators', moderatorsList.length)
                            : '';
                    this.$element.closest('.branch-button').find('.moderators-info')
                        .text(infoText)
                        .attr({'data-original-title': moderatorsList.join(', '), title: ''});
                    },
                required:       function() {
                    return $(branch).find('.checkbox-moderators').prop('checked');
                }
            });

            // when moderators checkbox is clicked, update userMultiPicker required property and, if unchecked,
            // disable input element to prevent from sending data for selected moderators when form is posted
            $(branch).find('.checkbox-moderators').on('click', function(){
                var checked = $(this).prop('checked');

                $(branch).find('.moderators-list input').prop('disabled', !checked);
                moderators.userMultiPicker('update');
                checkBranchSubForm();
            });

            // check the branch sub-form for initial errors
            checkBranchSubForm();

            // disable branch input elements if branch is read-only to prevent sending branches data
            if ($(branch).closest('.branches').is('.readonly')) {
                $(branch).find('input,textarea,.item-remove,.clear-branch-btn,.btn-mini').prop('disabled', true);
                $(branch).find('.close-branch-btn').text(swarm.te(' Close '));
            }
            // Setup the branch click event for user and group.
            $(branch).on('click.reviewer','button.subform-forced', function(e){
                swarm.project.toggleDefaultMenu(e);
            });
            $(branch).on('click.reviewer','.control-group-reviewers div.type-group button.subform-forced', function(e){
                swarm.project.toggleDefaultMenu(e);
            });
        },

        onOpenSubForm : function(element) {
            setTimeout(function() {
                $(element).parent().find(".subform-identity-element").focus();
            }, 0);
        },

        onCloseSubForm : function(element) {
            // if we have a label, update buttons
            // else remove this particular branch
            var label = $(element).parent().find(".subform-identity-element").val(),
                form  = $(element).closest('form');
            if (label) {
                $(element).html('<span class="branch-label"></span><span class="caret"></span>')
                          .find('span.branch-label')
                          .text(label);
            } else {
                $(element).closest('.branch-button').remove();
            }

            // re-validate the form to clear potential errors from the removed sub-form
            swarm.form.checkInvalid(form);
        },

        closeSubForm : function(element) {
            swarm.project.branch.onCloseSubForm($(element).dropdown('toggle'));
        },

        openNewSubForm: function(e) {
            e.preventDefault();
            e.stopPropagation();
            if($('.popover.popover-confirm').length > 0) {
                $('.popover-confirm .btn-cancel').click();
            }

            $('.branches .open .btn.dropdown-toggle').each(function() {
                swarm.project.branch.closeSubForm(this);
            });

            // find the subform template to render
            // and render the template into our dropdown menu
            var branchIndex     = swarm.project.branch._branchIndex,
                template        = $('.controls .branch-template'),
                newBranch       = template.children().clone(),
                nameField       = newBranch.find('.subform-identity-element'),
                pathsField      = newBranch.find('.branch-paths'),
                reviewersField  = newBranch.find('input.reviewers'),
                moderatorsField = newBranch.find('input.input-moderators');

            nameField.attr('name',      'branches[' + branchIndex + '][name]');
            pathsField.attr('name',     'branches[' + branchIndex + '][paths]');
            nameField.attr('id',        'branch-name-'  + branchIndex);
            pathsField.attr('id',       'branch-paths-' + branchIndex);
            pathsField.attr('required', true);

            nameField.siblings('label').attr('for',  'branch-name-'  + branchIndex);
            pathsField.siblings('label').attr('for', 'branch-paths-' + branchIndex);

            reviewersField.attr('id', 'branches-' + branchIndex + '-defaults-reviewers');
            reviewersField.data('input-name', 'branches[' + branchIndex + '][defaults][reviewers]');
            reviewersField.parents('.control-group-reviewers').find('label').attr('for', 'branches-' + branchIndex + '-defaults-reviewers');

            moderatorsField.attr('data-input-name', 'branches[' + branchIndex + '][moderators]');

            swarm.project.branch._branchIndex++;

            newBranch.insertBefore($(this).parent());
            newBranch.attr('id','branch-'+branchIndex);

            swarm.project.branch.initBranch(newBranch);
            newBranch.find('.btn.dropdown-toggle').dropdown('toggle');
            swarm.project.branch.onOpenSubForm(newBranch.find('.btn.dropdown-toggle'));
        }
    }
};

swarm.projects = {
    init: function(table, sortBy) {
        table = $(table);

        // enable all/my-projects dropdown if user is logged in
        // prefix heading with all or my as per default setting
        var handleLogin = function() {
            var scope  = swarm.localStorage.get('projects.scope') || 'all',
                prefix = scope === 'user' ? 'My ' : 'All ';
            table.find('thead .projects-title').text(swarm.t(prefix + 'Projects'));
            table.find('thead .projects-dropdown').addClass('dropdown');
        };

        if ($('body').is('.authenticated')) {
            handleLogin();
        }

        // enable dropdown and reload the table when user logs in
        $(document).on('swarm-login', function () {
            handleLogin();
            swarm.projects.load(table);
        });

        // connect to drop-down to switch between all/my-projects
        table.find('ul.dropdown-menu a').on('click', function (e) {
            e.preventDefault();
            var scope = $(this).closest('li').data('scope');
            swarm.localStorage.set('projects.scope', scope);
            swarm.projects.update(table, scope);
        });

        swarm.projects.load(table, sortBy);
    },

    sortByProjectName: function(projectA, projectB) {
        return swarm.projects.sortByString(projectA.name, projectB.name);
    },

    sortByNameIdArray: function(arrayA, arrayB) {
        return swarm.projects.sortByString(arrayA[0], arrayB[0]);
    },

    sortByString: function(nameA, nameB) {
        var upperNameA = nameA.toUpperCase(); // ignore upper and lowercase
        var upperNameB = nameB.toUpperCase(); // ignore upper and lowercase
        if (upperNameA < upperNameB) {
            return -1;
        }
        if (upperNameA > upperNameB) {
            return 1;
        }
        // names must be equal
        return 0;
    },

    sortById: function(projectA, projectB) {
        return swarm.projects.sortByString(projectA.id, projectB.id);
    },

    sortByRank: function(projectA, projectB) {
        var projectAFollowers = 0,
            projectBFollowers = 0;
        // We only need to check if followers are disabled for one project
        // As they will both be disabled.
        if (projectA.hasOwnProperty('followersDisabled') !== true){
            projectAFollowers = projectA.followers;
            projectBFollowers = projectB.followers;
        }
        return (projectB.members + projectBFollowers) - (projectA.members + projectAFollowers);
    },

    load: function(table, sortBy) {
        table = $(table);
        table.addClass('loading');

        $.ajax('/projects').done(function(data) {
            // each project gets ranked based on how many members and followers it has
            var rankings = data;
            if (sortBy === 'rank') {
                rankings.sort(swarm.projects.sortByRank);
            } else if (sortBy === 'id') {
                rankings.sort(swarm.projects.sortById);
            } else {
                rankings.sort(swarm.projects.sortByProjectName);
            }

            table.find('tbody').empty();
            $.each(data, function(){
                // determine project ranking on a scale of 0.5-1.0.
                var project        = this;
                project.users      = project.members;
                project.score      = $.inArray(project, rankings.slice().reverse()) / (rankings.length - 1);
                project.score      = Math.round((project.score * 0.5 + 0.5) * 10) / 10;
                var membersTitle   = swarm.tpe('%d Member', '%d Members', project.members);
                var followersTitle = '';
                if (project.hasOwnProperty('followersDisabled') !== true){
                    followersTitle = ', ' + swarm.tpe('%d Follower', '%d Followers', project.followers);
                    project.users  = project.members + project.followers;
                }
                var privateTitle   = swarm.te('Private Project');
                var noDesc         = swarm.te('No description');

                var row = $.templates(
                      '<tr class="project{{if project.isMember}} is-member{{/if}}">'
                    + ' <td>'
                    + '  <div class="metrics pull-right padw1 muted">'
                    + '   <span class="users badge count-{{>project.users}}"'
                    + '         style="opacity: {{>project.score}};"'
                    // The next line results in a custom class added to the tooltip
                    + '         data-customclass="project-users-badge"'
                    + '         title="{{>membersTitle}}{{>followersTitle}}">'
                    + '     {{>project.users}}'
                    + '   </span>'
                    + '  </div>'
                    + '  {{if project.isPrivate}}<i class="icon-eye-close private-project-icon" title="{{>privateTitle}}"></i> {{/if}}'
                    + '  <a href="{{url:"/projects"}}/{{urlc:project.id}}" class="force-wrap name">{{>project.name}}</a>'
                    + '  <p class="force-wrap description muted"><small>'
                    + '   {{if project.description}}{{:project.description}}{{else}}{{>noDesc}}{{/if}}'
                    + '  </small></p>'
                    + ' </td>'
                    + '</tr>'
                ).render({project        : project,
                          noDesc         : noDesc,
                          membersTitle   : membersTitle,
                          followersTitle : followersTitle,
                          privateTitle   : privateTitle});

                table.find('tbody').append(row);
            });

            // truncate the description
            table.find('.description').expander({slicePoint: 70});

            // update table to refresh heading and rows visibility
            swarm.projects.update(table);

            table.removeClass('loading').trigger('loaded');
        });
    },

    update: function(table, scope) {
        // determine whether to show all projects or 'my-projects'
        //  - if scope is explicitly passed, we always honor it
        //  - else, if user is logged in we check local-storage for a preference
        //          if user is not in any projects we show all to avoid an empty table
        var isAuthenticated = $('body').is('.authenticated'),
            memberRows      = table.find('tbody tr.is-member'),
            filterByMember  = scope === 'user';
        if (!scope) {
            filterByMember  = isAuthenticated && swarm.localStorage.get('projects.scope') === 'user';
            filterByMember  = memberRows.length ? filterByMember : false;
        }

        // update header - if dropdown enable, prefix with all/my
        var prefix = '';
        if (table.find('thead .projects-dropdown').is('.dropdown')) {
            prefix = filterByMember ? 'My ' : 'All ';
        }
        table.find('thead .projects-title').text(swarm.t(prefix + 'Projects'));

        // show/hide rows as per filterByMember
        var rows    = table.find('tbody tr').hide(),
            visible = filterByMember ? memberRows : rows;
        visible.show();

        // show alert if there are no visible projects
        table.find('tbody tr.projects-info').remove();
        if (!visible.length) {
            table.find('tbody').append(
                $(
                      '<tr class="projects-info"><td><div class="alert border-box pad3">'
                    + swarm.te('No projects.')
                    + '</div></td></tr>'
                )
            );
        }

        // set 'first/last-visible' class on the first/last visible row to assist
        // with styling via CSS as :first-child won't work when in 'my-projects' view
        rows.removeClass('first-visible last-visible');
        visible.first().addClass('first-visible');
        visible.last().addClass('last-visible');
    }
};
