@aiplacement @aiplacement_competency @javascript
Feature: Apply AI-suggested competencies
  In order to tag a course with competencies
  As a teacher
  I need to review the AI's suggestions and choose which ones to apply

  Background:
    Given the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "core_ai > ai providers" exist:
      | provider          | name            | enabled | apikey | orgid |
      | aiprovider_openai | OpenAI API test | 1       | 123    | abc   |
    And the following "activities" exist:
      | activity | name      | intro     | introformat | course | content     | contentformat | idnumber |
      | page     | PageName1 | PageDesc1 | 1           | C1     | PageContent | 1             | page1    |
    And the following config values are set as admin:
      | enabled | 1 | aiplacement_competency |

  # The drawer used to extend core's course assist placement, whose constructor dereferences
  # core's own drawer unchecked. On a page without it, that threw and stalled every other
  # JavaScript init on the page, so the "I am on" step below would time out.
  Scenario: The classify button opens the classification drawer
    Given I am on the "PageName1" "page activity editing" page logged in as "admin"
    And I expand all fieldsets
    When I click on "Classify Text" "button"
    Then I should see "Competency Framework Selection" in the "#ai-classify-drawer" "css_element"
    And I click on "#ai-classify-drawer-close" "css_element"
    # The drawer hides through a 0.2s visibility transition.
    And I wait "1" seconds
    And "#ai-classify-drawer" "css_element" should not be visible

  Scenario: The classify button is not shown when the placement is disabled
    Given the following config values are set as admin:
      | enabled | | aiplacement_competency |
    When I am on the "PageName1" "page activity editing" page logged in as "admin"
    Then "Classify Text" "button" should not exist

  # Opening the modal is what broke on Moodle 5.2, when core/modal_factory was removed and the
  # whole applycmps module stopped loading.
  Scenario: Apply opens the checklist with every suggestion selected
    Given I am on the "Course 1" "course editing" page logged in as "admin"
    And the AI classification suggests these competencies:
      | T0001 - Test one |
      | T0002 - Test two |
    When I click on "Apply" "button" in the ".course-assist-response" "css_element"
    Then "Apply classification" "dialogue" should be visible
    And I should see "T0001 - Test one" in the "Apply classification" "dialogue"
    And I should see "T0002 - Test two" in the "Apply classification" "dialogue"
    And the field "T0001 - Test one" matches value "1"
    And the field "T0002 - Test two" matches value "1"
    And I click on "Clear all" "button" in the "Apply classification" "dialogue"
    And the field "T0001 - Test one" matches value "0"
    And the field "T0002 - Test two" matches value "0"
