(() => {

    const module = ExternalModules.UWMadison.BetterSearch

    const html = `
    <div style="max-width:700px;">
        <table role="presentation" class="form_border" width="100%">
            <tbody>
                <tr>
                    <td class="header" colspan="2" style="font-weight:normal;padding:10px 5px;color:#800000;font-size:13px;">
                        <span>Better Search</span>                    
                    </td>
                </tr>
                <tr>
                    <td class="labelrc" style="width:275px;padding:10px 8px;">
                        <span>Search</span>                        
                        <div style="padding-top:4px;font-size:10px;font-weight:normal;color:#555;">
                            <span>
                                Begin typing to search the project data, then click an item in the list to navigate to that record.
                            </span>
                        </div>
                    </td>
                    <td class="data" style="padding:10px 8px;">
                        <input type="text" size="30" class="x-form-text x-form-field ui-autocomplete-input" autocomplete="off">
                    </td>
                </tr>
            </tbody>
        </table>
    </div>`

    $("head").append(`<style>#center {display: none;} </style>`)
    $(document).ready(() => {
        if (module.settings.hide_list) {
            $("[data-rc-lang=graphical_view_79]").closest("table").hide()
        }
        if (module.settings.hide_search) {
            $("[data-rc-lang=data_entry_138]").closest("table").hide()
        }
        $("#center > br").remove()
        $("#center").append(html).show()
        // TODO setup functionality
    })

})()